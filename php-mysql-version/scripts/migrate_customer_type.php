<?php
declare(strict_types=1);
// Egyszeri migráció: hozzáadja az orders.customer_type és orders.tax_number
// oszlopokat (a megrendelő cég vagy magánszemély, illetve cégnél az
// adószám) — a checkout most már ezt is kéri és tárolja. Böngészőből egyszer
// meg kell nyitni, utána törölni kell ezt a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$check = $mysqli->query("SHOW COLUMNS FROM orders LIKE 'customer_type'");
if ($check && $check->num_rows > 0) {
    echo "Az oszlop már létezik, nincs teendő.\n";
    exit;
}

if (!$mysqli->query(
    "ALTER TABLE orders
     ADD COLUMN customer_type ENUM('individual','company') NOT NULL DEFAULT 'individual' AFTER customer_phone,
     ADD COLUMN tax_number VARCHAR(20) NULL AFTER customer_type"
)) {
    die('Sikertelen migráció: ' . $mysqli->error);
}

echo "Sikeres migráció: customer_type és tax_number oszlopok hozzáadva az orders táblához.\n";
