<?php
declare(strict_types=1);
// Egyszeri migráció: a products.profile oszlopot INT-ről VARCHAR(10)-re
// alakítja, hogy néhány valós gumiméretnél előforduló, nem százalékos
// profiljelölést is el tudjon fogadni: "R" (pl. 175R14, radiál) vagy "-"
// (pl. 7.50-16, diagonál). A meglévő számként tárolt profilok (pl. 55)
// automatikusan szöveggé alakulnak ("55"), az adatuk nem vész el. Böngészőből
// egyszer meg kell nyitni, utána törölni kell ezt a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$check = $mysqli->query("SHOW COLUMNS FROM products LIKE 'profile'");
$column = $check ? $check->fetch_assoc() : null;
if ($column && stripos($column['Type'], 'varchar') === 0) {
    echo "A profile oszlop már szöveges típusú, nincs teendő.\n";
    exit;
}

if (!$mysqli->query('ALTER TABLE products MODIFY COLUMN profile VARCHAR(10) NULL')) {
    die('Sikertelen migráció: ' . $mysqli->error);
}

echo "Sikeres migráció: a profile oszlop mostantól szöveges (VARCHAR), elfogad R vagy - jelölést is.\n";
