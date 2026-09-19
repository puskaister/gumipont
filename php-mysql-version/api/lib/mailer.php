<?php
declare(strict_types=1);

// Egyszerű, függőségmentes SMTP-kliens (nincs Composer/PHPMailer a
// projektben). Sok megosztott tárhelyen a natív mail() függvény vagy
// egyáltalán nem küld semmit, vagy a levél spam-mappában landol (nincs
// hiteles feladó/SPF), ezért ha az api/config.php-ban be van állítva egy
// 'smtp' tömb (a tárhely saját postafiókjának adataival), azon keresztül,
// hitelesített kapcsolattal küldünk. Ha nincs 'smtp' konfiguráció, a régi
// mail()-alapú küldésre esünk vissza (helyi/teszt környezetekhez).

function send_app_email(array $config, string $to, string $subject, string $body, ?string $bcc = null): bool {
    $smtp = $config['smtp'] ?? null;
    if (is_array($smtp) && !empty($smtp['host']) && !empty($smtp['username']) && !empty($smtp['password'])) {
        if (smtp_send_mail($smtp, $to, $subject, $body, $bcc)) {
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
    if ($bcc !== null && $bcc !== '') {
        $headers .= "\r\nBcc: $bcc";
    }

    return @mail($to, $encodedSubject, $body, $headers);
}

function smtp_send_mail(array $smtp, string $to, string $subject, string $body, ?string $bcc = null): bool {
    $host = (string) ($smtp['host'] ?? '');
    $port = (int) ($smtp['port'] ?? 465);
    $username = (string) ($smtp['username'] ?? '');
    $password = (string) ($smtp['password'] ?? '');
    $from = (string) ($smtp['from'] ?? $username);

    if ($host === '' || $username === '' || $password === '') return false;

    $errno = 0;
    $errstr = '';
    $address = ($port === 465 ? 'ssl://' : '') . $host . ':' . $port;
    // A tanúsítvány-ellenőrzést lazábbra vesszük: sok megosztott tárhely
    // belső levelező-szerverének tanúsítvány-lánca nem felel meg PHP
    // alapértelmezett, szigorú ellenőrzésének (pl. hiányzó közbenső
    // tanúsítvány), miközben egy levelezőkliens ezt gyakran elnézi.
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);
    $socket = @stream_socket_client($address, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log("[gumipont smtp] Nem sikerült csatlakozni ($host:$port): $errstr ($errno)");
        return false;
    }
    stream_set_timeout($socket, 15);

    $readResponse = function () use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (!isset($line[3]) || $line[3] !== '-') break;
        }
        return $response;
    };

    $ok = true;
    $failedStep = null;
    $failedResponse = '';
    // Minden lépésnél MINDIG kiolvassuk a választ, akkor is, ha egy korábbi
    // lépés már hibázott — egy korábbi && rövidzár miatt ez korábban
    // elmaradt, ami szétcsúsztatta a kliens és a szerver közti
    // üzenetváltást (olvasatlan válaszok maradtak a socket bufferében).
    $step = function (string $name, ?string $command, string $expectedCode) use ($socket, $readResponse, &$ok, &$failedStep, &$failedResponse): void {
        if ($command !== null) fwrite($socket, $command . "\r\n");
        $response = $readResponse();
        if (substr($response, 0, 3) !== $expectedCode) {
            if ($ok) { $failedStep = $name; $failedResponse = trim($response); }
            $ok = false;
        }
    };

    $step('greeting', null, '220');
    $step('EHLO', 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '250');
    $step('AUTH LOGIN', 'AUTH LOGIN', '334');
    $step('username', base64_encode($username), '334');
    $step('password', base64_encode($password), '235');
    $step('MAIL FROM', "MAIL FROM:<$from>", '250');
    $step('RCPT TO', "RCPT TO:<$to>", '250');
    // A BCC-címzett egy plusz RCPT TO parancsot kap, de a levél fejlécében
    // (headers) sehol nem jelenik meg — így marad "titkos" másolat.
    if ($bcc !== null && $bcc !== '') {
        $step('RCPT TO (BCC)', "RCPT TO:<$bcc>", '250');
    }
    $step('DATA', 'DATA', '354');

    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
    $headers = "From: $from\r\nTo: $to\r\nSubject: $encodedSubject\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    // Az SMTP DATA-blokkot egy önálló sorban lévő pont zárja, ezért a
    // levéltestben soronként kezdődő pontokat duplázni kell (dot-stuffing).
    $escapedBody = preg_replace('/^\./m', '..', $body);
    $step('body', $headers . "\r\n" . $escapedBody . "\r\n.", '250');

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    if (!$ok) {
        error_log("[gumipont smtp] Sikertelen lépés ($failedStep): \"$failedResponse\" — $host:$port, $username");
    }

    return $ok;
}
