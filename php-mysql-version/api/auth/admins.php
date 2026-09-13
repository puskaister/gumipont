<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('POST');
require_auth_rate_limit($mysqli);

// Csak bejelentkezett admin hozhat létre másik admint. A hívó saját
// munkamenete változatlan marad — nem lépteti be automatikusan az újat.
require_admin($mysqli);

$body = request_body();
$name = trim((string) ($body['name'] ?? ''));
$email = strtolower(trim((string) ($body['email'] ?? '')));
$password = (string) ($body['password'] ?? '');

if ($name === '' || mb_strlen($name) > 120) error_response('Invalid input: name');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error_response('Invalid input: email');
if (strlen($password) < 8 || strlen($password) > 200) error_response('Invalid input: password');

$stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    error_response('Email already registered', 409);
}
$stmt->close();

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $mysqli->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, \'admin\')');
$stmt->bind_param('sss', $name, $email, $hash);
$stmt->execute();
$userId = $mysqli->insert_id;
$stmt->close();

respond(['user' => ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => 'admin']], 201);
