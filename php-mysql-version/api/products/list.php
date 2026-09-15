<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('GET');

$where = [];
$params = [];
$types = '';

if (isset($_GET['category']) && $_GET['category'] !== '') { $where[] = 'category = ?'; $params[] = (string) $_GET['category']; $types .= 's'; }
if (isset($_GET['width']) && $_GET['width'] !== '') { $where[] = 'width = ?'; $params[] = (int) $_GET['width']; $types .= 'i'; }
if (isset($_GET['profile']) && $_GET['profile'] !== '') { $where[] = 'profile = ?'; $params[] = (int) $_GET['profile']; $types .= 'i'; }
if (isset($_GET['rim']) && $_GET['rim'] !== '') { $where[] = 'rim = ?'; $params[] = (int) $_GET['rim']; $types .= 'i'; }
if (isset($_GET['holeCount']) && $_GET['holeCount'] !== '') { $where[] = 'hole_count = ?'; $params[] = (int) $_GET['holeCount']; $types .= 'i'; }
if (isset($_GET['pcd']) && $_GET['pcd'] !== '') { $where[] = 'pcd = ?'; $params[] = (string) $_GET['pcd']; $types .= 's'; }
if (isset($_GET['season']) && $_GET['season'] !== '') { $where[] = 'season = ?'; $params[] = (string) $_GET['season']; $types .= 's'; }
if (isset($_GET['vehicleType']) && $_GET['vehicleType'] !== '') { $where[] = 'vehicle_type = ?'; $params[] = (string) $_GET['vehicleType']; $types .= 's'; }
if (isset($_GET['brand']) && $_GET['brand'] !== '') { $where[] = 'brand = ?'; $params[] = (string) $_GET['brand']; $types .= 's'; }

$sql = 'SELECT * FROM products';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY id';

$stmt = $mysqli->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = stmt_fetch_all($stmt);

$products = [];
foreach ($rows as $row) {
    $products[] = [
        'id' => (int) $row['id'],
        'category' => $row['category'],
        'brand' => $row['brand'],
        'model' => $row['model'],
        'width' => $row['width'] !== null ? (int) $row['width'] : null,
        'profile' => $row['profile'] !== null ? (int) $row['profile'] : null,
        'rim' => (int) $row['rim'],
        'holeCount' => $row['hole_count'] !== null ? (int) $row['hole_count'] : null,
        'pcd' => $row['pcd'],
        'season' => $row['season'],
        'vehicleType' => $row['vehicle_type'],
        'price' => (float) $row['price'],
        'stock' => (int) $row['stock'],
        'speed' => $row['speed'],
        'loadIndex' => $row['load_index'],
        'image' => $row['image'],
        'description' => $row['description'],
    ];
}
$stmt->close();

respond(['products' => $products]);
