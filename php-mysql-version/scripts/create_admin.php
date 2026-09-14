<?php
declare(strict_types=1);
// Ez hozza létre az ELSŐ admin fiókot — utána az alkalmazásban bejelentkezve
// az admin tud további adminokat létrehozni ("Admin létrehozása" gomb).
//
// Ha van SSH/parancssor hozzáférésed a tárhelyhez:
//   php scripts/create_admin.php "Teljes Nev" email@cim.hu jelszo
//
// Ha NINCS parancssor hozzáférésed (sok olcsó megosztott tárhelynél nincs),
// nyisd meg böngészőben egyszer:
//   https://a-sajat-domained.hu/scripts/create_admin.php?name=Teljes+Nev&email=email@cim.hu&password=jelszo
// FONTOS: futtatás után töröld (vagy nevezd át) ezt a fájlt / a scripts/
// mappát, különben bárki létrehozhat vele admin fiókot, aki ismeri az URL-t.

$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    $name = $argv[1] ?? null;
    $email = $argv[2] ?? null;
    $password = $argv[3] ?? null;
    $fail = function (string $msg) {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    };
    $succeed = function (string $msg) {
        echo $msg . "\n";
        exit(0);
    };
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $name = $_GET['name'] ?? null;
    $email = $_GET['email'] ?? null;
    $password = $_GET['password'] ?? null;
    $fail = function (string $msg) {
        http_response_code(400);
        echo $msg . "\n";
        exit;
    };
    $succeed = function (string $msg) {
        echo $msg . "\n\nFONTOS: most már törölheted (vagy nevezd át) ezt a fájlt, hogy senki más ne tudjon vele admin fiókot létrehozni.\n";
        exit;
    };
}

if (!$name || !$email || !$password) {
    $usage = $isCli
        ? 'Használat: php scripts/create_admin.php "Teljes Nev" email@cim.hu jelszo'
        : 'Használat: create_admin.php?name=Teljes+Nev&email=email@cim.hu&password=jelszo';
    $fail($usage);
}

$config = require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/lib/db.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    $fail('Nem sikerült csatlakozni az adatbázishoz: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$email = strtolower(trim((string) $email));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $fail('Érvénytelen email cím.');
if (strlen((string) $password) < 8) $fail('A jelszó legalább 8 karakter legyen.');

$stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
if (stmt_fetch_one($stmt)) {
    $stmt->close();
    $fail("Már létezik felhasználó ezzel az email címmel: $email");
}
$stmt->close();

$hash = password_hash((string) $password, PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $mysqli->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
$stmt->bind_param('sss', $name, $email, $hash);
$stmt->execute();
$stmt->close();

$succeed("Admin fiók létrehozva: $email");
