<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/settings.php';
require __DIR__ . '/../lib/mailer.php';

require_method('POST');

// Vendégként (bejelentkezés nélkül) is lehet rendelni — csak akkor kötjük a
// rendeléshez a felhasználót, ha éppen van érvényes munkamenet.
$user = current_user($mysqli);

$body = json_input();
$customerName = trim((string) ($body['customerName'] ?? ''));
$customerEmail = strtolower(trim((string) ($body['customerEmail'] ?? '')));
$customerPhone = trim((string) ($body['customerPhone'] ?? ''));
$shippingZip = trim((string) ($body['shippingZip'] ?? ''));
$shippingCity = trim((string) ($body['shippingCity'] ?? ''));
$shippingStreet = trim((string) ($body['shippingStreet'] ?? ''));
$shippingHouseNo = trim((string) ($body['shippingHouseNumber'] ?? ''));
$deliveryMethod = (string) ($body['deliveryMethod'] ?? '');
$paymentMethod = (string) ($body['paymentMethod'] ?? '');
$items = is_array($body['items'] ?? null) ? $body['items'] : [];

if ($customerName === '' || mb_strlen($customerName) > 200) error_response('Invalid input: customerName');
if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) error_response('Invalid input: customerEmail');
if ($customerPhone === '') error_response('Invalid input: customerPhone');
if (!in_array($deliveryMethod, ['courier', 'pickup'], true)) error_response('Invalid input: deliveryMethod');
if (!in_array($paymentMethod, ['card', 'transfer', 'cash'], true)) error_response('Invalid input: paymentMethod');
if ($deliveryMethod === 'courier' && ($shippingZip === '' || $shippingCity === '' || $shippingStreet === '' || $shippingHouseNo === '')) {
    error_response('Az irányítószám, a város, az utca és a házszám megadása kötelező futáros kiszállításnál.');
}
if (!$items) error_response('Invalid input: items');

// A régebbi, egyben tárolt "shipping_address" mezőt is feltöltjük a
// tagolt részekből — ez marad a kompakt megjelenítéshez (pl. rendelések
// listája), a tagolt mezők pedig a részletes nézethez és az emailhez.
$shippingAddress = $deliveryMethod === 'courier'
    ? trim("$shippingZip $shippingCity, $shippingStreet $shippingHouseNo.")
    : '';

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
        'shippingCost' => (float) $product['shipping_cost'],
        'brand' => $product['brand'],
        'model' => $product['model'],
    ];
}

$subtotal = array_reduce($resolved, fn ($sum, $it) => $sum + $it['unitPrice'] * $it['qty'], 0.0);

// A szállítási díjat és a tömeges kedvezményt a szerver számolja — a kliens
// sosem adhatja meg közvetlenül, különben tetszőleges kedvezményt/ingyenes
// szállítást tudna beállítani magának. Futáros kiszállításnál a díj a
// kosárban lévő termékek saját (egyenként beállított) szállítási
// költségének összege; átvételnél a beállításokban rögzített, egységes díj
// marad érvényben (az nem függ attól, mit veszel át).
if ($deliveryMethod === 'pickup') {
    $shipping = get_setting($mysqli, 'shipping');
    $shippingCost = (float) ($shipping['pickup'] ?? 0);
} else {
    $shippingCost = array_reduce($resolved, fn ($sum, $it) => $sum + $it['shippingCost'] * $it['qty'], 0.0);
}

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
            shipping_zip, shipping_city, shipping_street, shipping_house_no,
            subtotal, discount, shipping_cost, total
        ) VALUES (?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'issssssssssdddd',
        $userId, $deliveryMethod, $paymentMethod, $customerName, $customerEmail, $customerPhone,
        $shippingAddress, $shippingZip, $shippingCity, $shippingStreet, $shippingHouseNo,
        $subtotal, $discount, $shippingCost, $total
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
    'shipping_zip' => $shippingZip, 'shipping_city' => $shippingCity,
    'shipping_street' => $shippingStreet, 'shipping_house_no' => $shippingHouseNo,
    'subtotal' => $subtotal, 'discount' => $discount, 'shipping_cost' => $shippingCost, 'total' => $total,
];
$orderItems = array_map(fn ($it) => [
    'order_id' => $orderId, 'product_id' => $it['productId'], 'qty' => $it['qty'], 'unit_price' => $it['unitPrice'],
], $resolved);

notify_new_order($config, $orderId, $order, $resolved, $deliveryMethod, $paymentMethod);

respond(['order' => $order, 'items' => $orderItems], 201);

// Értesítő email a rendeles@gumipont.hu címre minden új rendelésnél (titkos
// másolatban puskaisandor@gmail.com-nak is) — az api/config.php 'smtp'
// beállításán keresztül (ha ki van töltve), különben a natív mail()
// függvényre esik vissza (lásd api/lib/mailer.php).
function notify_new_order(array $config, int $orderId, array $order, array $items, string $deliveryMethod, string $paymentMethod): void {
    $to = 'rendeles@gumipont.hu';
    $bcc = 'puskaisandor@gmail.com';

    $deliveryLabel = $deliveryMethod === 'pickup' ? 'Átvétel' : 'Futár';
    $paymentLabel = ['card' => 'Kártya', 'transfer' => 'Átutalás', 'cash' => 'Készpénz'][$paymentMethod] ?? $paymentMethod;

    $lines = [];
    $lines[] = "Új rendelés érkezett - #$orderId";
    $lines[] = '';
    $lines[] = 'Vevő adatai';
    $lines[] = '-----------';
    $lines[] = 'Név: ' . $order['customer_name'];
    $lines[] = 'Email: ' . $order['customer_email'];
    $lines[] = 'Telefonszám: ' . $order['customer_phone'];
    $lines[] = '';
    $lines[] = 'Szállítás';
    $lines[] = '---------';
    $lines[] = "Mód: $deliveryLabel";
    if ($deliveryMethod === 'courier') {
        $lines[] = 'Irányítószám: ' . $order['shipping_zip'];
        $lines[] = 'Város: ' . $order['shipping_city'];
        $lines[] = 'Utca: ' . $order['shipping_street'];
        $lines[] = 'Házszám: ' . $order['shipping_house_no'];
    }
    $lines[] = "Fizetési mód: $paymentLabel";
    $lines[] = '';
    $lines[] = 'Tételek';
    $lines[] = '-------';
    foreach ($items as $it) {
        $lineTotal = number_format($it['unitPrice'] * $it['qty'], 0, ',', ' ');
        $unitPrice = number_format($it['unitPrice'], 0, ',', ' ');
        $lines[] = "  - {$it['brand']} {$it['model']} x{$it['qty']} @ $unitPrice = $lineTotal";
    }
    $lines[] = '';
    $lines[] = 'Összesítés';
    $lines[] = '----------';
    $lines[] = 'Részösszeg: ' . number_format($order['subtotal'], 0, ',', ' ');
    $lines[] = 'Kedvezmény: ' . number_format($order['discount'], 0, ',', ' ');
    $lines[] = 'Szállítási díj: ' . number_format($order['shipping_cost'], 0, ',', ' ');
    $lines[] = 'Végösszeg: ' . number_format($order['total'], 0, ',', ' ');
    $messageBody = implode("\n", $lines);

    $sent = send_app_email($config, $to, "Új rendelés #$orderId - gumipont.hu", $messageBody, $bcc);
    if (!$sent) {
        error_log("[gumipont uj rendeles ertesito] Nem sikerult emailt kuldeni a(z) #$orderId rendelesrol");
    }
}
