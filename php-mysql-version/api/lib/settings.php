<?php
declare(strict_types=1);

const SETTINGS_DEFAULTS = [
    'currency'     => 'HUF',
    'shipping'     => ['courier' => 15, 'pickup' => 0],
    'bulkDiscount' => ['minQty' => 0, 'percent' => 0],
];

function get_setting(mysqli $mysqli, string $key) {
    $stmt = $mysqli->prepare('SELECT `value` FROM settings WHERE `key` = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = stmt_fetch_one($stmt);
    $stmt->close();

    if (!$row) return SETTINGS_DEFAULTS[$key] ?? null;
    $decoded = json_decode($row['value'], true);
    return $decoded === null && $row['value'] !== 'null' ? (SETTINGS_DEFAULTS[$key] ?? null) : $decoded;
}

function set_setting(mysqli $mysqli, string $key, $value): void {
    $json = json_encode($value);
    $stmt = $mysqli->prepare(
        'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    );
    $stmt->bind_param('ss', $key, $json);
    $stmt->execute();
    $stmt->close();
}

function get_all_settings(mysqli $mysqli): array {
    $result = $mysqli->query('SELECT `key`, `value` FROM settings');
    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $decoded = json_decode($row['value'], true);
        $settings[$row['key']] = $decoded;
    }
    return $settings;
}
