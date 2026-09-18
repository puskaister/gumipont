<?php
declare(strict_types=1);
// Egyszeri migráció: hozzáadja a products.shipping_cost oszlopot, hogy a
// szállítási költséget mostantól termékenként (kerekenként) lehessen
// megadni a korábbi egységes, beállításokban rögzített díj helyett. A
// meglévő termékek 0-val indulnak, admin utólag be tudja állítani
// egyenként. Böngészőből egyszer meg kell nyitni, utána törölni kell ezt
// a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$check = $mysqli->query("SHOW COLUMNS FROM products LIKE 'shipping_cost'");
if ($check && $check->num_rows > 0) {
    echo "Az oszlop már létezik, nincs teendő.\n";
    exit;
}

if (!$mysqli->query("ALTER TABLE products ADD COLUMN shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER price")) {
    die('Sikertelen migráció: ' . $mysqli->error);
}

echo "Sikeres migráció: shipping_cost oszlop hozzáadva a products táblához.\n";
