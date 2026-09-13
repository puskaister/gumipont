<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('POST');
require_admin($mysqli);

$body = request_body();
$id = trim((string) ($body['id'] ?? ''));
if ($id === '') error_response('Invalid input: id');

$stmt = $mysqli->prepare('DELETE FROM pages WHERE id = ?');
$stmt->bind_param('s', $id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) error_response('Page not found', 404);

http_response_code(204);
