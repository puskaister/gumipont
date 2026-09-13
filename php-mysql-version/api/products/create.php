<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/uploads.php';

require_method('POST');
require_admin($mysqli);

$brand = trim((string) ($_POST['brand'] ?? ''));
$model = trim((string) ($_POST['model'] ?? ''));
$width = (int) ($_POST['width'] ?? 0);
$profile = (int) ($_POST['profile'] ?? 0);
$rim = (int) ($_POST['rim'] ?? 0);
$season = (string) ($_POST['season'] ?? '');
$price = (float) ($_POST['price'] ?? -1);
$stock = (int) ($_POST['stock'] ?? -1);
$speed = trim((string) ($_POST['speed'] ?? ''));
$loadIndex = trim((string) ($_POST['loadIndex'] ?? ''));

if ($brand === '' || $model === '') error_response('Invalid input: brand/model');
if ($width <= 0 || $profile <= 0 || $rim <= 0) error_response('Invalid input: size');
if (!in_array($season, ['summer', 'winter', 'all-season'], true)) error_response('Invalid input: season');
if ($price < 0) error_response('Invalid input: price');
if ($stock < 0) error_response('Invalid input: stock');

$image = save_uploaded_image('image');

$stmt = $mysqli->prepare(
    'INSERT INTO products (brand, model, width, profile, rim, season, price, stock, speed, load_index, image)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param(
    'ssiiisdisss',
    $brand, $model, $width, $profile, $rim, $season, $price, $stock, $speed, $loadIndex, $image
);
// Típusjelzők sorrendben: brand=s model=s width=i profile=i rim=i season=s
// price=d stock=i speed=s loadIndex=s image=s (11 érték -> 11 jelző).
$stmt->execute();
$id = $mysqli->insert_id;
$stmt->close();

respond(['product' => [
    'id' => $id, 'brand' => $brand, 'model' => $model, 'width' => $width, 'profile' => $profile,
    'rim' => $rim, 'season' => $season, 'price' => $price, 'stock' => $stock,
    'speed' => $speed, 'loadIndex' => $loadIndex, 'image' => $image,
]], 201);
