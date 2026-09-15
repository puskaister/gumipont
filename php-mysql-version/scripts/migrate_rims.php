<?php
declare(strict_types=1);
// Egyszeri migráció: felni termékek támogatásához hozzáadja a category és
// hole_count oszlopokat, és a width/profile/season mezőket nullázhatóvá
// teszi (a felni termékeknek nincs szélessége/profilja/évszaka). A meglévő
// sorok category='tire' alapértékkel maradnak. Böngészőből egyszer meg kell
// nyitni, utána törölni kell ezt a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$check = $mysqli->query("SHOW COLUMNS FROM products LIKE 'category'");
if ($check && $check->num_rows > 0) {
    echo "Az oszlopok már léteznek, nincs teendő.\n";
    exit;
}

$statements = [
    "ALTER TABLE products ADD COLUMN category ENUM('tire','rim') NOT NULL DEFAULT 'tire' AFTER id",
    "ALTER TABLE products ADD COLUMN hole_count INT NULL AFTER rim",
    "ALTER TABLE products MODIFY width INT NULL",
    "ALTER TABLE products MODIFY profile INT NULL",
    "ALTER TABLE products MODIFY season ENUM('summer','winter','all-season') NULL",
];

foreach ($statements as $sql) {
    if (!$mysqli->query($sql)) {
        die('Sikertelen migráció (' . $sql . '): ' . $mysqli->error);
    }
}

echo "Sikeres migráció: category és hole_count oszlopok hozzáadva, width/profile/season nullázható.\n";
