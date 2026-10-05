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
$customerType = (string) ($body['customerType'] ?? 'individual');
$taxNumber = trim((string) ($body['taxNumber'] ?? ''));
$shippingZip = trim((string) ($body['shippingZip'] ?? ''));
$shippingCity = trim((string) ($body['shippingCity'] ?? ''));
$shippingStreet = trim((string) ($body['shippingStreet'] ?? ''));
$shippingHouseNo = trim((string) ($body['shippingHouseNumber'] ?? ''));
$deliveryMethod = (string) ($body['deliveryMethod'] ?? '');
$paymentMethod = (string) ($body['paymentMethod'] ?? '');
$termsAccepted = (bool) ($body['termsAccepted'] ?? false);
$items = is_array($body['items'] ?? null) ? $body['items'] : [];

if ($customerName === '' || mb_strlen($customerName) > 200) error_response('Invalid input: customerName');
if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) error_response('Invalid input: customerEmail');
if ($customerPhone === '' || !is_valid_hu_phone($customerPhone)) error_response('Invalid input: customerPhone');
if (!in_array($customerType, ['individual', 'company'], true)) error_response('Invalid input: customerType');
if ($customerType === 'company' && $taxNumber === '') error_response('Cégként vásárláshoz az adószám megadása kötelező.');
if ($customerType !== 'company') $taxNumber = '';
if (!in_array($deliveryMethod, ['courier', 'pickup'], true)) error_response('Invalid input: deliveryMethod');
if (!in_array($paymentMethod, ['card', 'transfer', 'cash'], true)) error_response('Invalid input: paymentMethod');
if ($deliveryMethod === 'courier' && ($shippingZip === '' || $shippingCity === '' || $shippingStreet === '' || $shippingHouseNo === '')) {
    error_response('Az irányítószám, a város, az utca és a házszám megadása kötelező futáros kiszállításnál.');
}
if (!$termsAccepted) error_response('A vásárlási feltételek elfogadása kötelező.');
if (!$items) error_response('Invalid input: items');

function is_valid_hu_phone(string $v): bool {
    $digits = preg_replace('/[\s\-().]/', '', $v);
    return (bool) preg_match('/^(\+36|06)\d{8,9}$/', $digits);
}

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

    // Ha a készlet nem fedezi a kért mennyiséget (akár részben, akár
    // teljesen), a rendelés sosem utasítódik el — a tétel helyette két
    // sorra bomlik: ami ténylegesen készleten van, és a hiányzó rész
    // "Rendelhető (nincs készleten)" jelöléssel (utánrendelés).
    $stock = (int) $product['stock'];
    $availableQty = max(0, min($stock, $qty));
    $backorderQty = $qty - $availableQty;

    $itemBase = [
        'productId' => $productId,
        'unitPrice' => (float) $product['price'],
        'shippingCost' => (float) $product['shipping_cost'],
        'brand' => $product['brand'],
        'model' => $product['model'],
    ];
    if ($availableQty > 0) {
        $resolved[] = $itemBase + ['qty' => $availableQty, 'note' => null];
    }
    if ($backorderQty > 0) {
        $resolved[] = $itemBase + ['qty' => $backorderQty, 'note' => 'Rendelhető (nincs készleten)'];
    }
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
            customer_name, customer_email, customer_phone, customer_type, tax_number, shipping_address,
            shipping_zip, shipping_city, shipping_street, shipping_house_no,
            subtotal, discount, shipping_cost, total
        ) VALUES (?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'issssssssssssdddd',
        $userId, $deliveryMethod, $paymentMethod, $customerName, $customerEmail, $customerPhone, $customerType, $taxNumber,
        $shippingAddress, $shippingZip, $shippingCity, $shippingStreet, $shippingHouseNo,
        $subtotal, $discount, $shippingCost, $total
    );
    $stmt->execute();
    $orderId = $mysqli->insert_id;
    $stmt->close();

    $itemStmt = $mysqli->prepare('INSERT INTO order_items (order_id, product_id, qty, unit_price, note) VALUES (?, ?, ?, ?, ?)');
    // GREATEST(...,0): utánrendelhető (0 készletű, vagy a hiányzó résznél)
    // a készlet nem megy negatívba, egyszerűen 0 marad a rendelés után is.
    $stockStmt = $mysqli->prepare('UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?');
    foreach ($resolved as $it) {
        $itemStmt->bind_param('iiids', $orderId, $it['productId'], $it['qty'], $it['unitPrice'], $it['note']);
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
    'customer_phone' => $customerPhone, 'customer_type' => $customerType, 'tax_number' => $taxNumber,
    'shipping_address' => $shippingAddress,
    'shipping_zip' => $shippingZip, 'shipping_city' => $shippingCity,
    'shipping_street' => $shippingStreet, 'shipping_house_no' => $shippingHouseNo,
    'subtotal' => $subtotal, 'discount' => $discount, 'shipping_cost' => $shippingCost, 'total' => $total,
];
$orderItems = array_map(fn ($it) => [
    'order_id' => $orderId, 'product_id' => $it['productId'], 'qty' => $it['qty'], 'unit_price' => $it['unitPrice'], 'note' => $it['note'],
], $resolved);

notify_new_order($config, $mysqli, $orderId, $order, $resolved, $deliveryMethod, $paymentMethod);

respond(['order' => $order, 'items' => $orderItems], 201);

// Értesítő email a rendeles@gumipont.hu címre minden új rendelésnél (titkos
// másolatban puskaisandor@gmail.com-nak is) — az api/config.php 'smtp'
// beállításán keresztül (ha ki van töltve), különben a natív mail()
// függvényre esik vissza (lásd api/lib/mailer.php).
function notify_new_order(array $config, mysqli $mysqli, int $orderId, array $order, array $items, string $deliveryMethod, string $paymentMethod): void {
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
    $lines[] = 'Típus: ' . ($order['customer_type'] === 'company' ? 'Cég (adószám: ' . $order['tax_number'] . ')' : 'Magánszemély');
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
        $noteSuffix = !empty($it['note']) ? " — {$it['note']}" : '';
        $lines[] = "  - {$it['brand']} {$it['model']} x{$it['qty']} @ $unitPrice = $lineTotal$noteSuffix";
    }
    $lines[] = '';
    $lines[] = 'Összesítés';
    $lines[] = '----------';
    $lines[] = 'Részösszeg: ' . number_format($order['subtotal'], 0, ',', ' ');
    if ((float) $order['discount'] > 0) {
        $lines[] = 'Kedvezmény: ' . number_format($order['discount'], 0, ',', ' ');
    }
    $lines[] = 'Szállítási díj: ' . number_format($order['shipping_cost'], 0, ',', ' ');
    $lines[] = 'Végösszeg: ' . number_format($order['total'], 0, ',', ' ');
    $messageBody = implode("\r\n", $lines);

    $htmlBody = render_order_invoice_html($mysqli, $orderId, $order, $items, $deliveryLabel, $paymentLabel);

    $sent = send_app_email($config, $to, "Új rendelés #$orderId - gumipont.hu", $messageBody, $bcc, $htmlBody);
    if (!$sent) {
        error_log("[gumipont uj rendeles ertesito] Nem sikerult emailt kuldeni a(z) #$orderId rendelesrol");
    }
}

// Számlakép az értesítő emailbe: eladó / vevő blokk, tételtábla, összesítés.
// Csak inline stílusokkal (a levelezőkliensek nagy része a <style> blokkot
// eldobja). Nem hivatalos számla — azt a számlázó program állítja ki.
function render_order_invoice_html(mysqli $mysqli, int $orderId, array $order, array $items, string $deliveryLabel, string $paymentLabel): string {
    $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $currency = (string) (get_setting($mysqli, 'currency') ?: 'HUF');
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ' . $h($currency);

    // Eladó adatai az oldal "Elérhetőségeink" tartalmából.
    $contact = [];
    $res = $mysqli->query("SELECT `value` FROM site_content WHERE `key` = 'contact'");
    if ($res && ($row = $res->fetch_assoc())) {
        $contact = json_decode((string) $row['value'], true) ?: [];
    }
    $sellerLines = array_filter([
        'Gumipont Szerviz Kft.',
        $contact['address'] ?? '',
        !empty($contact['phone']) ? 'Tel.: ' . $contact['phone'] : '',
        $contact['email'] ?? '',
    ]);

    $buyerLines = [$order['customer_name']];
    if ($order['customer_type'] === 'company') $buyerLines[] = 'Adószám: ' . $order['tax_number'];
    if ($deliveryLabel !== 'Átvétel' && $order['shipping_zip'] !== '') {
        $buyerLines[] = $order['shipping_zip'] . ' ' . $order['shipping_city'];
        $buyerLines[] = $order['shipping_street'] . ' ' . $order['shipping_house_no'] . '.';
    }
    $buyerLines[] = $order['customer_email'];
    $buyerLines[] = $order['customer_phone'];

    $block = fn (array $lines) => implode('<br>', array_map($h, $lines));
    $date = (new DateTime('now', new DateTimeZone('Europe/Budapest')))->format('Y.m.d. H:i');

    $td = 'padding:8px 10px;border-bottom:1px solid #e3e3e3;font-size:14px;';
    $th = 'padding:8px 10px;border-bottom:2px solid #222;font-size:12px;text-align:left;text-transform:uppercase;letter-spacing:.04em;color:#555;';
    $rows = '';
    foreach ($items as $it) {
        $note = !empty($it['note']) ? '<br><span style="color:#c0392b;font-size:12px;">' . $h($it['note']) . '</span>' : '';
        $rows .= '<tr>'
            . '<td style="' . $td . '"><strong>' . $h($it['brand']) . '</strong> ' . $h($it['model']) . $note . '</td>'
            . '<td style="' . $td . 'text-align:right;">' . (int) $it['qty'] . ' db</td>'
            . '<td style="' . $td . 'text-align:right;white-space:nowrap;">' . $money($it['unitPrice']) . '</td>'
            . '<td style="' . $td . 'text-align:right;white-space:nowrap;">' . $money($it['unitPrice'] * $it['qty']) . '</td>'
            . '</tr>';
    }

    $sumRow = fn (string $label, string $value, bool $strong = false) =>
        '<tr><td style="padding:4px 10px;text-align:right;font-size:' . ($strong ? '16px;font-weight:700;border-top:2px solid #222;padding-top:8px;' : '14px;') . '">' . $h($label)
        . '</td><td style="padding:4px 10px;text-align:right;white-space:nowrap;font-size:' . ($strong ? '16px;font-weight:700;border-top:2px solid #222;padding-top:8px;' : '14px;') . '">' . $value . '</td></tr>';
    $sums = $sumRow('Részösszeg', $money($order['subtotal']));
    if ((float) $order['discount'] > 0) $sums .= $sumRow('Kedvezmény', '−' . $money($order['discount']));
    $sums .= $sumRow('Szállítási díj', $money($order['shipping_cost']));
    $sums .= $sumRow('Végösszeg', $money($order['total']), true);

    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f2f2f2;font-family:Arial,Helvetica,sans-serif;color:#222;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #ddd;">'
        . '<tr><td style="padding:24px 28px;border-bottom:3px solid #222;">'
        .   '<table role="presentation" width="100%"><tr>'
        .   '<td style="font-size:22px;font-weight:700;">gumipont.hu</td>'
        .   '<td style="text-align:right;font-size:13px;color:#555;">Rendelés száma: <strong style="color:#222;">#' . $orderId . '</strong><br>Dátum: ' . $h($date) . '</td>'
        .   '</tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 28px;">'
        .   '<table role="presentation" width="100%"><tr>'
        .   '<td style="vertical-align:top;width:50%;font-size:14px;line-height:1.5;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#777;margin-bottom:4px;">Eladó</div>' . $block($sellerLines) . '</td>'
        .   '<td style="vertical-align:top;width:50%;font-size:14px;line-height:1.5;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#777;margin-bottom:4px;">Vevő</div>' . $block($buyerLines) . '</td>'
        .   '</tr></table>'
        .   '<p style="font-size:13px;color:#555;margin:16px 0 0;">Szállítás: <strong style="color:#222;">' . $h($deliveryLabel) . '</strong> &nbsp;·&nbsp; Fizetés: <strong style="color:#222;">' . $h($paymentLabel) . '</strong></p>'
        . '</td></tr>'
        . '<tr><td style="padding:0 28px;">'
        .   '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        .   '<tr><th style="' . $th . '">Tétel</th><th style="' . $th . 'text-align:right;">Menny.</th><th style="' . $th . 'text-align:right;">Egységár</th><th style="' . $th . 'text-align:right;">Összeg</th></tr>'
        .   $rows
        .   '</table>'
        . '</td></tr>'
        . '<tr><td style="padding:12px 28px 24px;"><table role="presentation" align="right" cellpadding="0" cellspacing="0">' . $sums . '</table></td></tr>'
        . '<tr><td style="padding:14px 28px;background:#fafafa;border-top:1px solid #e3e3e3;font-size:11px;color:#888;">Ez a számlakép a rendelés összesítője, nem minősül számlának.</td></tr>'
        . '</table></body></html>';
}
