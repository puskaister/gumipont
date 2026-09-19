<?php
declare(strict_types=1);
// Egyszeri migráció: hozzáadja a rendelésekhez a tagolt szállítási cím
// mezőket (irányítószám, város, utca, házszám) a korábbi egyetlen
// "shipping_address" mező mellé. A régi rendeléseknél ezek üresen
// maradnak, az admin felület ilyenkor a régi egyben tárolt címet mutatja.
// Böngészőből egyszer meg kell nyitni, utána törölni kell ezt a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$check = $mysqli->query("SHOW COLUMNS FROM orders LIKE 'shipping_zip'");
if ($check && $check->num_rows > 0) {
    echo "Az oszlopok már léteznek, nincs teendő.\n";
    exit;
}

$statements = [
    "ALTER TABLE orders ADD COLUMN shipping_zip VARCHAR(10) NULL AFTER shipping_address",
    "ALTER TABLE orders ADD COLUMN shipping_city VARCHAR(120) NULL AFTER shipping_zip",
    "ALTER TABLE orders ADD COLUMN shipping_street VARCHAR(200) NULL AFTER shipping_city",
    "ALTER TABLE orders ADD COLUMN shipping_house_no VARCHAR(20) NULL AFTER shipping_street",
];

foreach ($statements as $sql) {
    if (!$mysqli->query($sql)) {
        die('Sikertelen migráció (' . $sql . '): ' . $mysqli->error);
    }
}

echo "Sikeres migráció: shipping_zip / shipping_city / shipping_street / shipping_house_no oszlopok hozzáadva.\n";
