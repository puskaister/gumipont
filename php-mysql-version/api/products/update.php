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

$brand = array_key_exists('brand', $_POST) ? trim((string) $_POST['brand']) : $existing['brand'];
$model = array_key_exists('model', $_POST) ? trim((string) $_POST['model']) : $existing['model'];
$width = array_key_exists('width', $_POST) ? (int) $_POST['width'] : (int) $existing['width'];
$profile = array_key_exists('profile', $_POST) ? (int) $_POST['profile'] : (int) $existing['profile'];
$rim = array_key_exists('rim', $_POST) ? (int) $_POST['rim'] : (int) $existing['rim'];
$season = array_key_exists('season', $_POST) ? (string) $_POST['season'] : $existing['season'];
$vehicleType = array_key_exists('vehicleType', $_POST) ? (string) $_POST['vehicleType'] : $existing['vehicle_type'];
$price = array_key_exists('price', $_POST) ? (float) $_POST['price'] : (float) $existing['price'];
$stock = array_key_exists('stock', $_POST) ? (int) $_POST['stock'] : (int) $existing['stock'];
$speed = array_key_exists('speed', $_POST) ? trim((string) $_POST['speed']) : $existing['speed'];
$loadIndex = array_key_exists('loadIndex', $_POST) ? trim((string) $_POST['loadIndex']) : $existing['load_index'];

if ($brand === '' || $model === '') error_response('Invalid input: brand/model');
if ($width <= 0 || $profile <= 0 || $rim <= 0) error_response('Invalid input: size');
if (!in_array($season, ['summer', 'winter', 'all-season'], true)) error_response('Invalid input: season');
if (!in_array($vehicleType, ['car', 'truck'], true)) error_response('Invalid input: vehicleType');
if ($price < 0) error_response('Invalid input: price');
if ($stock < 0) error_response('Invalid input: stock');

$uploadedImage = save_uploaded_image('image');
if ($uploadedImage !== null) {
    $image = $uploadedImage;
} elseif (($_POST['removeImage'] ?? '') === 'true') {
    $image = null;
} else {
    $image = $existing['image'];
}

$stmt = $mysqli->prepare(
    'UPDATE products SET brand=?, model=?, width=?, profile=?, rim=?, season=?, vehicle_type=?, price=?, stock=?, speed=?, load_index=?, image=? WHERE id=?'
);
$stmt->bind_param(
    'ssiiissdisssi',
    $brand, $model, $width, $profile, $rim, $season, $vehicleType, $price, $stock, $speed, $loadIndex, $image, $id
);
$stmt->execute();
$stmt->close();

respond(['product' => [
    'id' => $id, 'brand' => $brand, 'model' => $model, 'width' => $width, 'profile' => $profile,
    'rim' => $rim, 'season' => $season, 'vehicleType' => $vehicleType, 'price' => $price, 'stock' => $stock,
    'speed' => $speed, 'loadIndex' => $loadIndex, 'image' => $image,
]]);
