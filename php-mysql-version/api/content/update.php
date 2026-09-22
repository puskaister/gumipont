<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('POST');
require_admin($mysqli);

const ALLOWED_KEYS = ['shop_intro', 'hero_size', 'about', 'contact', 'terms', 'privacy'];

$body = request_body();
$key = (string) ($body['key'] ?? '');
if (!in_array($key, ALLOWED_KEYS, true)) error_response('Unknown content key');
if (!array_key_exists('value', $body)) error_response('"value" is required');

$value = $body['value'];
// JSON testű kérésnél a value már dekódolt PHP érték; multipart/form POST-nál
// (ha valaha úgy hívnák) stringként jönne — itt csak a JSON útvonalat használjuk.
$json = json_encode($value);

$stmt = $mysqli->prepare(
    'INSERT INTO site_content (`key`, `value`) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
);
$stmt->bind_param('ss', $key, $json);
$stmt->execute();
$stmt->close();

respond(['key' => $key, 'value' => $value]);
