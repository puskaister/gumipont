<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('POST');
require_admin($mysqli);

$body = request_body();
$id = (int) ($body['id'] ?? 0);
if ($id <= 0) error_response('Invalid input: id');

$stmt = $mysqli->prepare('DELETE FROM products WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) error_response('Product not found', 404);

http_response_code(204);
