<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('GET');

$result = $mysqli->query('SELECT id, title, content FROM pages');
$pages = [];
while ($row = $result->fetch_assoc()) {
    $pages[] = $row;
}

respond(['pages' => $pages]);
