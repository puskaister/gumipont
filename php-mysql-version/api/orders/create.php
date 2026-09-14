<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/settings.php';

require_method('POST');

// Vendégként (bejelentkezés nélkül) is lehet rendelni — csak akkor kötjük a
// rendeléshez a felhasználót, ha éppen van érvényes munkamenet.
$user = current_user($mysqli);

$body = json_input();
$customerName = trim((string) ($body['customerName'] ?? ''));
$customerEmail = strtolower(trim((string) ($body['customerEmail'] ?? '')));
$customerPhone = trim((string) ($body['customerPhone'] ?? ''));
$shippingAddress = trim((string) ($body['shippingAddress'] ?? ''));
$deliveryMethod = (string) ($body['deliveryMethod'] ?? '');
$paymentMethod = (string) ($body['paymentMethod'] ?? '');
$items = is_array($body['items'] ?? null) ? $body['items'] : [];

if ($customerName === '' || mb_strlen($customerName) > 200) error_response('Invalid input: customerName');
if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) error_response('Invalid input: customerEmail');
if ($customerPhone === '') error_response('Invalid input: customerPhone');
if (!in_array($deliveryMethod, ['courier', 'pickup'], true)) error_response('Invalid input: deliveryMethod');
if (!in_array($paymentMethod, ['card', 'transfer', 'cash'], true)) error_response('Invalid input: paymentMethod');
if ($deliveryMethod === 'courier' && $shippingAddress === '') {
    error_response('Szállítási cím megadása kötelező futáros kiszállításnál.');
}
if (!$items) error_response('Invalid input: items');

// Tételek feloldása + készlet-ellenőrzés + fajlagos ár lekérése.
$resolved = [];
foreach ($items as $item) {
    $productId = (int) ($item['productId'] ?? 0);
    $qty = (int) ($item['qty'] ?? 0);
    if ($productId <= 0 || $qty <= 0) error_response('Invalid input: items');

    $stmt = $mysqli->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $product = stmt_fetch_one($stmt);
    $stmt->close();

    if (!$product) error_response("Product $productId not found");
    if ((int) $product['stock'] < $qty) error_response("Insufficient stock for product $productId");

    $resolved[] = [
        'productId' => $productId,
        'qty' => $qty,
        'unitPrice' => (float) $product['price'],
        'brand' => $product['brand'],
        'model' => $product['model'],
    ];
}

$subtotal = array_reduce($resolved, fn ($sum, $it) => $sum + $it['unitPrice'] * $it['qty'], 0.0);

// A szállítási díjat és a tömeges kedvezményt a szerver számolja a
// beállítások alapján — a kliens sosem adhatja meg közvetlenül, különben
// tetszőleges kedvezményt/ingyenes szállítást tudna beállítani magának.
$shipping = get_setting($mysqli, 'shipping');
$shippingCost = (float) ($deliveryMethod === 'pickup' ? $shipping['pickup'] : $shipping['courier']);

$bulkDiscount = get_setting($mysqli, 'bulkDiscount');
$totalQty = array_reduce($resolved, fn ($sum, $it) => $sum + $it['qty'], 0);
$discount = 0.0;
if (($bulkDiscount['minQty'] ?? 0) > 0 && ($bulkDiscount['percent'] ?? 0) > 0 && $totalQty >= $bulkDiscount['minQty']) {
    $discount = round($subtotal * ($bulkDiscount['percent'] / 100), 2);
}

$total = max(0, $subtotal - $discount) + $shippingCost;

$mysqli->begin_transaction();
try {
    $userId = $user ? (int) $user['id'] : null;
    $stmt = $mysqli->prepare(
        "INSERT INTO orders (
            user_id, status, delivery_method, payment_method,
            customer_name, customer_email, customer_phone, shipping_address,
            subtotal, discount, shipping_cost, total
        ) VALUES (?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'issssssdddd',
        $userId, $deliveryMethod, $paymentMethod, $customerName, $customerEmail, $customerPhone,
        $shippingAddress, $subtotal, $discount, $shippingCost, $total
    );
    $stmt->execute();
    $orderId = $mysqli->insert_id;
    $stmt->close();

    $itemStmt = $mysqli->prepare('INSERT INTO order_items (order_id, product_id, qty, unit_price) VALUES (?, ?, ?, ?)');
    $stockStmt = $mysqli->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
    foreach ($resolved as $it) {
        $itemStmt->bind_param('iiid', $orderId, $it['productId'], $it['qty'], $it['unitPrice']);
        $itemStmt->execute();
        $stockStmt->bind_param('ii', $it['qty'], $it['productId']);
        $stockStmt->execute();
    }
    $itemStmt->close();
    $stockStmt->close();

    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    error_response('Nem sikerült létrehozni a rendelést.', 500);
}

$order = [
    'id' => $orderId, 'user_id' => $userId, 'status' => 'new',
    'delivery_method' => $deliveryMethod, 'payment_method' => $paymentMethod,
    'customer_name' => $customerName, 'customer_email' => $customerEmail,
    'customer_phone' => $customerPhone, 'shipping_address' => $shippingAddress,
    'subtotal' => $subtotal, 'discount' => $discount, 'shipping_cost' => $shippingCost, 'total' => $total,
];
$orderItems = array_map(fn ($it) => [
    'order_id' => $orderId, 'product_id' => $it['productId'], 'qty' => $it['qty'], 'unit_price' => $it['unitPrice'],
], $resolved);

respond(['order' => $order, 'items' => $orderItems], 201);
