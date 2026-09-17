<?php
declare(strict_types=1);

// Egyszerű, függőségmentes SMTP-kliens (nincs Composer/PHPMailer a
// projektben). Sok megosztott tárhelyen a natív mail() függvény vagy
// egyáltalán nem küld semmit, vagy a levél spam-mappában landol (nincs
// hiteles feladó/SPF), ezért ha az api/config.php-ban be van állítva egy
// 'smtp' tömb (a tárhely saját postafiókjának adataival), azon keresztül,
// hitelesített kapcsolattal küldünk. Ha nincs 'smtp' konfiguráció, a régi
// mail()-alapú küldésre esünk vissza (helyi/teszt környezetekhez).

function send_app_email(array $config, string $to, string $subject, string $body): bool {
    $smtp = $config['smtp'] ?? null;
    if (is_array($smtp) && !empty($smtp['host']) && !empty($smtp['username']) && !empty($smtp['password'])) {
        if (smtp_send_mail($smtp, $to, $subject, $body)) {
            return true;
        }
        // Ha az SMTP-küldés hibázik, még megpróbáljuk a natív mail()-t is,
        // mielőtt teljesen feladnánk.
    }

    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fromDomain = preg_replace('/^www\./', '', explode(':', $host)[0]);
    $from = $smtp['from'] ?? "no-reply@$fromDomain";
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "From: $from";

    return @mail($to, $encodedSubject, $body, $headers);
}

function smtp_send_mail(array $smtp, string $to, string $subject, string $body): bool {
    $host = (string) ($smtp['host'] ?? '');
    $port = (int) ($smtp['port'] ?? 465);
    $username = (string) ($smtp['username'] ?? '');
    $password = (string) ($smtp['password'] ?? '');
    $from = (string) ($smtp['from'] ?? $username);

    if ($host === '' || $username === '' || $password === '') return false;

    $errno = 0;
    $errstr = '';
    $address = ($port === 465 ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($address, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        error_log("[gumipont smtp] Nem sikerült csatlakozni ($host:$port): $errstr ($errno)");
        return false;
    }
    stream_set_timeout($socket, 15);

    $readResponse = function () use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response = $line;
            if (!isset($line[3]) || $line[3] !== '-') break;
        }
        return $response;
    };
    $expect = function (string $expectedCode) use ($readResponse): bool {
        return substr($readResponse(), 0, 3) === $expectedCode;
    };
    $send = function (string $data) use ($socket): void {
        fwrite($socket, $data . "\r\n");
    };

    $ok = $expect('220');
    $send('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $ok = $ok && $expect('250');
    $send('AUTH LOGIN');
    $ok = $ok && $expect('334');
    $send(base64_encode($username));
    $ok = $ok && $expect('334');
    $send(base64_encode($password));
    $ok = $ok && $expect('235');
    $send("MAIL FROM:<$from>");
    $ok = $ok && $expect('250');
    $send("RCPT TO:<$to>");
    $ok = $ok && $expect('250');
    $send('DATA');
    $ok = $ok && $expect('354');

    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
    $headers = "From: $from\r\nTo: $to\r\nSubject: $encodedSubject\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    // Az SMTP DATA-blokkot egy önálló sorban lévő pont zárja, ezért a
    // levéltestben soronként kezdődő pontokat duplázni kell (dot-stuffing).
    $escapedBody = preg_replace('/^\./m', '..', $body);
    $send($headers . "\r\n" . $escapedBody . "\r\n.");
    $ok = $ok && $expect('250');

    $send('QUIT');
    fclose($socket);

    if (!$ok) {
        error_log("[gumipont smtp] Az SMTP-küldés sikertelen ($host:$port, $username)");
    }

    return $ok;
}
