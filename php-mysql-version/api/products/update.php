<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/uploads.php';

// A böngésző nem tud kényelmesen multipart PUT-ot küldeni, ezért ez POST-ot
// vár egy _method=PUT mezővel (lásd require_method a bootstrap.php-ban).
require_method('PUT');
require_admin($mysqli);

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) error_response('Invalid input: id');

$stmt = $mysqli->prepare('SELECT * FROM products WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$existing = stmt_fetch_one($stmt);
$stmt->close();
if (!$existing) error_response('Product not found', 404);

$category = array_key_exists('category', $_POST) ? (string) $_POST['category'] : $existing['category'];
if (!in_array($category, ['tire', 'rim'], true)) error_response('Invalid input: category');

$brand = array_key_exists('brand', $_POST) ? trim((string) $_POST['brand']) : $existing['brand'];
$model = array_key_exists('model', $_POST) ? trim((string) $_POST['model']) : $existing['model'];
$rim = array_key_exists('rim', $_POST) ? trim((string) $_POST['rim']) : (string) $existing['rim'];
$vehicleType = array_key_exists('vehicleType', $_POST) ? (string) $_POST['vehicleType'] : $existing['vehicle_type'];
$price = array_key_exists('price', $_POST) ? (float) $_POST['price'] : (float) $existing['price'];
$shippingCost = array_key_exists('shippingCost', $_POST) ? (float) $_POST['shippingCost'] : (float) $existing['shipping_cost'];
$stock = array_key_exists('stock', $_POST) ? (int) $_POST['stock'] : (int) $existing['stock'];
$description = array_key_exists('description', $_POST) ? trim((string) $_POST['description']) : $existing['description'];

if ($brand === '' || $model === '') error_response('Invalid input: brand/model');
if ($rim === '') error_response('Invalid input: size');
if (!in_array($vehicleType, ['car', 'truck'], true)) error_response('Invalid input: vehicleType');
if ($price < 0) error_response('Invalid input: price');
if ($shippingCost < 0) error_response('Invalid input: shippingCost');
if ($stock < 0) error_response('Invalid input: stock');

if ($category === 'tire') {
    $width = array_key_exists('width', $_POST) ? (int) $_POST['width'] : (int) ($existing['width'] ?? 0);
    $profile = array_key_exists('profile', $_POST) ? trim((string) $_POST['profile']) : ((string) ($existing['profile'] ?? ''));
    $season = array_key_exists('season', $_POST) ? (string) $_POST['season'] : ((string) ($existing['season'] ?? ''));
    $speed = array_key_exists('speed', $_POST) ? trim((string) $_POST['speed']) : $existing['speed'];
    $loadIndex = array_key_exists('loadIndex', $_POST) ? trim((string) $_POST['loadIndex']) : $existing['load_index'];
    $holeCount = null;
    $pcd = null;
    $et = null;
    $rimWidth = null;

    if ($width <= 0 || $profile === '') error_response('Invalid input: size');
    if (!in_array($season, ['summer', 'winter', 'all-season'], true)) error_response('Invalid input: season');
} else {
    $width = null;
    $profile = null;
    $season = null;
    $speed = array_key_exists('speed', $_POST) ? trim((string) $_POST['speed']) : ((string) ($existing['speed'] ?? ''));
    $loadIndex = array_key_exists('loadIndex', $_POST) ? trim((string) $_POST['loadIndex']) : ((string) ($existing['load_index'] ?? ''));
    $holeCount = array_key_exists('holeCount', $_POST) ? (int) $_POST['holeCount'] : (int) ($existing['hole_count'] ?? 0);
    $pcd = array_key_exists('pcd', $_POST) ? trim((string) $_POST['pcd']) : ((string) ($existing['pcd'] ?? ''));
    $et = array_key_exists('et', $_POST) ? trim((string) $_POST['et']) : ((string) ($existing['et'] ?? ''));
    if ($et === '') $et = null;
    $rimWidth = array_key_exists('rimWidth', $_POST) ? trim((string) $_POST['rimWidth']) : ((string) ($existing['rim_width'] ?? ''));
    if ($rimWidth === '') $rimWidth = null;

    if ($holeCount <= 0) error_response('Invalid input: holeCount');
    if ($pcd === '') error_response('Invalid input: pcd');
    if ($et !== null) {
        $etNum = (float) str_replace(',', '.', $et);
        if ($etNum < -50 || $etNum > 250) error_response('Invalid input: et');
    }
}

$uploadedImage = save_uploaded_image('image');
if ($uploadedImage !== null) {
    $image = $uploadedImage;
} elseif (($_POST['removeImage'] ?? '') === 'true') {
    $image = null;
} else {
    $image = $existing['image'];
}

$stmt = $mysqli->prepare(
    'UPDATE products SET category=?, brand=?, model=?, width=?, profile=?, rim=?, rim_width=?, hole_count=?, pcd=?, et=?, season=?, vehicle_type=?, price=?, shipping_cost=?, stock=?, speed=?, load_index=?, image=?, description=? WHERE id=?'
);
$stmt->bind_param(
    'sssisssissssddissssi',
    $category, $brand, $model, $width, $profile, $rim, $rimWidth, $holeCount, $pcd, $et, $season, $vehicleType, $price, $shippingCost, $stock, $speed, $loadIndex, $image, $description, $id
);
$stmt->execute();
$stmt->close();

respond(['product' => [
    'id' => $id, 'category' => $category, 'brand' => $brand, 'model' => $model, 'width' => $width, 'profile' => $profile,
    'rim' => $rim, 'rimWidth' => $rimWidth, 'holeCount' => $holeCount, 'pcd' => $pcd, 'et' => $et, 'season' => $season, 'vehicleType' => $vehicleType, 'price' => $price,
    'shippingCost' => $shippingCost, 'stock' => $stock, 'speed' => $speed, 'loadIndex' => $loadIndex, 'image' => $image, 'description' => $description,
]]);
