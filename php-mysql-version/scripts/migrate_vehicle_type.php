<?php
declare(strict_types=1);
// Egyszeri migráció: hozzáadja a vehicle_type oszlopot a products táblához
// (a meglévő sorok 'car' alapértékkel maradnak). Böngészőből egyszer meg
// kell nyitni, utána törölni kell ezt a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$check = $mysqli->query("SHOW COLUMNS FROM products LIKE 'vehicle_type'");
if ($check && $check->num_rows > 0) {
    echo "Az oszlop már létezik, nincs teendő.\n";
    exit;
}

if (!$mysqli->query("ALTER TABLE products ADD COLUMN vehicle_type ENUM('car','truck') NOT NULL DEFAULT 'car' AFTER season")) {
    die('Sikertelen migráció: ' . $mysqli->error);
}

echo "Sikeres migráció: vehicle_type oszlop hozzáadva.\n";
