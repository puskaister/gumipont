<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('GET');

$id = (string) ($_GET['id'] ?? '');
if ($id === '') error_response('Invalid input: id');

$stmt = $mysqli->prepare('SELECT id, title, content FROM pages WHERE id = ?');
$stmt->bind_param('s', $id);
$stmt->execute();
$page = stmt_fetch_one($stmt);
$stmt->close();

if (!$page) error_response('Page not found', 404);

respond(['page' => $page]);
