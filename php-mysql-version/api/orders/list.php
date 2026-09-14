<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('GET');
require_admin($mysqli);

$orders = [];
$result = $mysqli->query('SELECT * FROM orders ORDER BY created_at DESC');
while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}

respond(['orders' => attach_order_items($mysqli, $orders)]);

function attach_order_items(mysqli $mysqli, array $orders): array {
    if (!$orders) return $orders;
    $ids = array_map(fn ($o) => (int) $o['id'], $orders);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $mysqli->prepare(
        "SELECT oi.order_id, oi.qty, oi.unit_price, p.brand, p.model
         FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = stmt_fetch_all($stmt);

    $byOrder = [];
    foreach ($rows as $row) {
        $byOrder[$row['order_id']][] = [
            'brand' => $row['brand'],
            'model' => $row['model'],
            'qty' => (int) $row['qty'],
            'unitPrice' => (float) $row['unit_price'],
        ];
    }
    $stmt->close();

    foreach ($orders as &$order) {
        $order['items'] = $byOrder[$order['id']] ?? [];
    }
    return $orders;
}
