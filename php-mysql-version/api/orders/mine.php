<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('GET');
$user = require_auth($mysqli);

$stmt = $mysqli->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$orders = [];
while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}
$stmt->close();

if ($orders) {
    $ids = array_map(fn ($o) => (int) $o['id'], $orders);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $itemsStmt = $mysqli->prepare(
        "SELECT oi.order_id, oi.qty, oi.unit_price, p.brand, p.model
         FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id IN ($placeholders)"
    );
    $itemsStmt->bind_param($types, ...$ids);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();

    $byOrder = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $byOrder[$row['order_id']][] = [
            'brand' => $row['brand'],
            'model' => $row['model'],
            'qty' => (int) $row['qty'],
            'unitPrice' => (float) $row['unit_price'],
        ];
    }
    $itemsStmt->close();

    foreach ($orders as &$order) {
        $order['items'] = $byOrder[$order['id']] ?? [];
    }
    unset($order);
}

respond(['orders' => $orders]);
