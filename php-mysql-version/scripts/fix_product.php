<?php
declare(strict_types=1);
// Egyszeri javító script: a Michelin termék típusneve tévedésből "Michelin"
// lett a márka helyett/mellett — visszaállítja egy rendes típusnévre.
// Böngészőből egyszer meg kell nyitni, utána törölni kell ezt a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/lib/db.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$stmt = $mysqli->prepare("UPDATE products SET model = ? WHERE brand = 'Michelin' AND model = 'Michelin'");
$newModel = 'Road Grip S2';
$stmt->bind_param('s', $newModel);
$stmt->execute();
echo "Frissített sorok száma: " . $stmt->affected_rows . "\n";
$stmt->close();
