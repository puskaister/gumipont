<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('POST');
require_auth_rate_limit($mysqli);

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
if (stmt_fetch_one($stmt)) {
    $stmt->close();
    error_response('Email already registered', 409);
}
$stmt->close();

// A publikus regisztráció mindig sima 'user' fiókot hoz létre — nincs
// kliens felől megadható mód admin jog szerzésére. Lásd auth/admins.php.
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $mysqli->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, \'user\')');
$stmt->bind_param('sss', $name, $email, $hash);
$stmt->execute();
$userId = $mysqli->insert_id;
$stmt->close();

$_SESSION['user_id'] = $userId;

respond(['user' => ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => 'user']], 201);
