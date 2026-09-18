<?php
declare(strict_types=1);
// Egyszeri migráció: a products.rim oszlopot DECIMAL(4,1)-ről VARCHAR(10)-re
// állítja, hogy betűs utótagú méretek is tárolhatók legyenek (pl. "16C" a
// megerősített/kereskedelmi gumiknál). A meglévő számértékek (pl. 22.5)
// változatlanul megmaradnak szövegként ("22.5"). Böngészőből egyszer meg
// kell nyitni, utána törölni kell ezt a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$check = $mysqli->query("SHOW COLUMNS FROM products LIKE 'rim'");
$column = $check ? $check->fetch_assoc() : null;
if ($column && stripos($column['Type'], 'varchar') !== false) {
    echo "A rim oszlop már szöveges típusú, nincs teendő.\n";
    exit;
}

if (!$mysqli->query('ALTER TABLE products MODIFY rim VARCHAR(10) NOT NULL')) {
    die('Sikertelen migráció: ' . $mysqli->error);
}

echo "Sikeres migráció: a rim oszlop mostantól betűs utótagot is elfogad (pl. 16C).\n";
