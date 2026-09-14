<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('POST');
require_auth_rate_limit($mysqli);

$body = request_body();
$token = (string) ($body['token'] ?? '');
$password = (string) ($body['password'] ?? '');

if ($token === '') error_response('Invalid input');
if (strlen($password) < 8 || strlen($password) > 200) error_response('Invalid input');

// A lejáratot PHP írta (forgot_password.php: date('Y-m-d H:i:s', time()+3600)),
// ezért az összehasonlítást is PHP-oldali "most"-tal végezzük — a MySQL
// NOW() a szerver saját (gyakran a PHP-étól eltérő) időzónáját használná,
// ami miatt egy frissen kiküldött token azonnal lejártnak tűnhetne.
$now = date('Y-m-d H:i:s');
$tokenHash = hash('sha256', $token);
$stmt = $mysqli->prepare('SELECT * FROM users WHERE reset_token_hash = ? AND reset_token_expires > ?');
$stmt->bind_param('ss', $tokenHash, $now);
$stmt->execute();
$user = stmt_fetch_one($stmt);
$stmt->close();

if (!$user) error_response('Érvénytelen vagy lejárt link.');

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $mysqli->prepare('UPDATE users SET password_hash = ?, reset_token_hash = NULL, reset_token_expires = NULL WHERE id = ?');
$stmt->bind_param('si', $hash, $user['id']);
$stmt->execute();
$stmt->close();

$_SESSION['user_id'] = (int) $user['id'];

respond(['user' => [
    'id' => (int) $user['id'],
    'name' => $user['name'],
    'email' => $user['email'],
    'role' => $user['role'],
]]);
