<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/xlsx.php';

require_method('POST');
require_admin($mysqli);

$id = trim((string) ($_POST['id'] ?? ''));
$title = trim((string) ($_POST['title'] ?? ''));
if ($id === '' || $title === '') error_response('Invalid input: id/title');

$content = (string) ($_POST['content'] ?? '');

if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        error_response('File upload failed');
    }
    try {
        $content = xlsx_or_csv_to_html($_FILES['file']['tmp_name'], $_FILES['file']['name']);
    } catch (Throwable $e) {
        error_response('Could not parse the uploaded file: ' . $e->getMessage());
    }
}

$stmt = $mysqli->prepare(
    'INSERT INTO pages (id, title, content) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)'
);
$stmt->bind_param('sss', $id, $title, $content);
$stmt->execute();
$stmt->close();

respond(['page' => ['id' => $id, 'title' => $title, 'content' => $content]], 201);
