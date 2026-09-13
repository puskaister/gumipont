<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('GET');

$result = $mysqli->query('SELECT `key`, `value` FROM site_content');
$content = [];
while ($row = $result->fetch_assoc()) {
    $decoded = json_decode($row['value'], true);
    $content[$row['key']] = $decoded !== null || $row['value'] === 'null' ? $decoded : $row['value'];
}

respond(['content' => $content]);
