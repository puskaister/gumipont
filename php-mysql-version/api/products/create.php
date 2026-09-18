<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/uploads.php';

require_method('POST');
require_admin($mysqli);

$category = (string) ($_POST['category'] ?? 'tire');
if (!in_array($category, ['tire', 'rim'], true)) error_response('Invalid input: category');

$brand = trim((string) ($_POST['brand'] ?? ''));
$model = trim((string) ($_POST['model'] ?? ''));
$rim = trim((string) ($_POST['rim'] ?? ''));
$vehicleType = (string) ($_POST['vehicleType'] ?? 'car');
$price = (float) ($_POST['price'] ?? -1);
$shippingCost = (float) ($_POST['shippingCost'] ?? -1);
$stock = (int) ($_POST['stock'] ?? -1);
$description = trim((string) ($_POST['description'] ?? ''));

if ($brand === '' || $model === '') error_response('Invalid input: brand/model');
if ($rim === '') error_response('Invalid input: size');
if (!in_array($vehicleType, ['car', 'truck'], true)) error_response('Invalid input: vehicleType');
if ($price < 0) error_response('Invalid input: price');
if ($shippingCost < 0) error_response('Invalid input: shippingCost');
if ($stock < 0) error_response('Invalid input: stock');

if ($category === 'tire') {
    $width = (int) ($_POST['width'] ?? 0);
    $profile = (int) ($_POST['profile'] ?? 0);
    $season = (string) ($_POST['season'] ?? '');
    $speed = trim((string) ($_POST['speed'] ?? ''));
    $loadIndex = trim((string) ($_POST['loadIndex'] ?? ''));
    $holeCount = null;
    $pcd = null;

    if ($width <= 0 || $profile <= 0) error_response('Invalid input: size');
    if (!in_array($season, ['summer', 'winter', 'all-season'], true)) error_response('Invalid input: season');
} else {
    $width = null;
    $profile = null;
    $season = null;
    $speed = '';
    $loadIndex = '';
    $holeCount = (int) ($_POST['holeCount'] ?? 0);
    $pcd = trim((string) ($_POST['pcd'] ?? ''));

    if ($holeCount <= 0) error_response('Invalid input: holeCount');
    if ($pcd === '') error_response('Invalid input: pcd');
}

$image = save_uploaded_image('image');

$stmt = $mysqli->prepare(
    'INSERT INTO products (category, brand, model, width, profile, rim, hole_count, pcd, season, vehicle_type, price, shipping_cost, stock, speed, load_index, image, description)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param(
    'sssiisisssddissss',
    $category, $brand, $model, $width, $profile, $rim, $holeCount, $pcd, $season, $vehicleType, $price, $shippingCost, $stock, $speed, $loadIndex, $image, $description
);
// Típusjelzők sorrendben: category=s brand=s model=s width=i profile=i rim=s
// hole_count=i pcd=s season=s vehicleType=s price=d shipping_cost=d stock=i
// speed=s loadIndex=s image=s description=s (17 érték -> 17 jelző).
// width/profile/season/hole_count/pcd NULL is lehet a kategóriától függően —
// bind_param NULL-t is elfogad.
$stmt->execute();
$id = $mysqli->insert_id;
$stmt->close();

respond(['product' => [
    'id' => $id, 'category' => $category, 'brand' => $brand, 'model' => $model, 'width' => $width, 'profile' => $profile,
    'rim' => $rim, 'holeCount' => $holeCount, 'pcd' => $pcd, 'season' => $season, 'vehicleType' => $vehicleType, 'price' => $price,
    'shippingCost' => $shippingCost, 'stock' => $stock, 'speed' => $speed, 'loadIndex' => $loadIndex, 'image' => $image, 'description' => $description,
]], 201);
