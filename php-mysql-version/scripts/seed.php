<?php
declare(strict_types=1);
// Feltölti a 10 alap gumi terméket, mintaadatként (törli a meglévő termékeket előtte!).
//
// Parancssorból: php scripts/seed.php
// Böngészőből (ha nincs SSH-d): nyisd meg egyszer a scripts/seed.php URL-t,
// majd töröld/nevezd át a fájlt, hogy ne lehessen véletlenül újra lefuttatni.

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

function seed_fail(string $msg): void {
    echo $msg . "\n";
    exit(1);
}

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    seed_fail('Nem sikerült csatlakozni az adatbázishoz: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$products = [
    ['Kordon', 'Road Grip S2', 205, 55, 16, 'summer', 98, 24, 'V', '91'],
    ['Aerowall', 'Silent Line', 205, 55, 16, 'all-season', 104, 6, 'H', '91'],
    ['Nortrek', 'Ice Command', 205, 55, 16, 'winter', 112, 0, 'T', '91'],
    ['Solmark', 'Apex R', 225, 45, 17, 'summer', 139, 14, 'W', '94'],
    ['Vantis', 'Cross Country', 225, 65, 17, 'all-season', 127, 19, 'H', '102'],
    ['Halcyon', 'Polar Line', 225, 45, 17, 'winter', 145, 3, 'V', '94'],
    ['Kordon', 'City Runner', 185, 60, 15, 'all-season', 78, 31, 'T', '84'],
    ['Solmark', 'Track Day', 245, 40, 18, 'summer', 189, 8, 'Y', '97'],
    ['Aerowall', 'Frost Guard', 195, 65, 15, 'winter', 89, 0, 'T', '91'],
    ['Vantis', 'Longhaul Plus', 215, 60, 16, 'all-season', 118, 22, 'H', '95'],
];

$mysqli->query('DELETE FROM products');
$stmt = $mysqli->prepare(
    'INSERT INTO products (brand, model, width, profile, rim, season, price, stock, speed, load_index, image)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
);
foreach ($products as $p) {
    [$brand, $model, $width, $profile, $rim, $season, $price, $stock, $speed, $loadIndex] = $p;
    $stmt->bind_param('ssiiisdiss', $brand, $model, $width, $profile, $rim, $season, $price, $stock, $speed, $loadIndex);
    $stmt->execute();
}
$stmt->close();

echo 'Seeded ' . count($products) . " products.\n";
