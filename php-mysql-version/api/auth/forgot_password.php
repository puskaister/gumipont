<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/mailer.php';

require_method('POST');
require_auth_rate_limit($mysqli);

$body = request_body();
$email = strtolower(trim((string) ($body['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error_response('Invalid input');

$stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = stmt_fetch_one($stmt);
$stmt->close();

// Ugyanazt a választ adjuk vissza, ha a fiók létezik vagy sem — így ez a
// végpont nem használható arra, hogy kiderüljön, mely email címek vannak
// regisztrálva.
if ($user) {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + 3600); // 1 óra

    $stmt = $mysqli->prepare('UPDATE users SET reset_token_hash = ?, reset_token_expires = ? WHERE id = ?');
    $stmt->bind_param('ssi', $tokenHash, $expires, $user['id']);
    $stmt->execute();
    $stmt->close();

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // api/auth/forgot_password.php -> 3 szinttel feljebb a webgyökér.
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '', 3), '/');
    $resetUrl = "$scheme://$host$basePath/index.html?resetToken=$token";

    $messageBody = "Szia!\r\n\r\nJelszó-visszaállítást kértél. Nyisd meg az alábbi linket (1 órán belül érvényes):\r\n\r\n$resetUrl\r\n\r\nHa nem te kérted, hagyd figyelmen kívül ezt az emailt.";

    $sent = send_app_email($config, $email, 'Jelszó visszaállítása - gumipont.hu', $messageBody);
    if (!$sent) {
        // A legtöbb megosztott tárhelyen a mail() működik, de helyi/teszt
        // környezetben (nincs beállítva sendmail/SMTP) nem biztos. Ilyenkor
        // a link a PHP error logba kerül, hogy fejlesztéskor is elérhető légy.
        error_log("[gumipont jelszo-visszaallitas] $email -> $resetUrl");
    }
}

respond(['message' => 'Ha létezik fiók ezzel az email címmel, elküldtük a jelszó-visszaállító linket.']);
