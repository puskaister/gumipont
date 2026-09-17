<?php
declare(strict_types=1);
// Egyszeri migráció: a products.rim oszlopot INT-ről DECIMAL(4,1)-re
// állítja, hogy a féltizedes felniméretek (pl. teherautó gumiknál 22,5)
// is tárolhatók legyenek — eddig ezek 22-re kerekedtek. A meglévő egész
// értékek (pl. 16) változatlanul megmaradnak (16.0-ként tárolódnak, de a
// felület továbbra is csak "16"-ot mutat). Böngészőből egyszer meg kell
// nyitni, utána törölni kell ezt a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$check = $mysqli->query("SHOW COLUMNS FROM products LIKE 'rim'");
$column = $check ? $check->fetch_assoc() : null;
if ($column && stripos($column['Type'], 'decimal') !== false) {
    echo "A rim oszlop már decimal típusú, nincs teendő.\n";
    exit;
}

if (!$mysqli->query('ALTER TABLE products MODIFY rim DECIMAL(4,1) NOT NULL')) {
    die('Sikertelen migráció: ' . $mysqli->error);
}

echo "Sikeres migráció: a rim oszlop mostantól féltizedes méreteket is elfogad (pl. 22.5).\n";
