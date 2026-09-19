<?php
declare(strict_types=1);
// Egyszeri migráció: hozzáadja a products.rim_width oszlopot (felni
// szélessége, pl. 6,5 vagy 7J) — szövegként tárolva, hogy tizedes és betűs
// jelölést is elfogadjon, ugyanúgy mint a pcd/et mezők. Nem kötelező mező, a
// meglévő felni termékek üresen maradnak, amíg admin ki nem tölti. Böngészőből
// egyszer meg kell nyitni, utána törölni kell ezt a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$check = $mysqli->query("SHOW COLUMNS FROM products LIKE 'rim_width'");
if ($check && $check->num_rows > 0) {
    echo "Az oszlop már létezik, nincs teendő.\n";
    exit;
}

if (!$mysqli->query("ALTER TABLE products ADD COLUMN rim_width VARCHAR(10) NULL AFTER rim")) {
    die('Sikertelen migráció: ' . $mysqli->error);
}

echo "Sikeres migráció: rim_width oszlop hozzáadva a products táblához.\n";
