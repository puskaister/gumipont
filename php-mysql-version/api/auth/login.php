<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('POST');
require_auth_rate_limit($mysqli);

$body = request_body();
$email = strtolower(trim((string) ($body['email'] ?? '')));
$password = (string) ($body['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    error_response('Invalid input');
}

$stmt = $mysqli->prepare('SELECT * FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($password, $user['password_hash'])) {
    error_response('Invalid email or password', 401);
}

$_SESSION['user_id'] = (int) $user['id'];

respond(['user' => [
    'id' => (int) $user['id'],
    'name' => $user['name'],
    'email' => $user['email'],
    'role' => $user['role'],
]]);
