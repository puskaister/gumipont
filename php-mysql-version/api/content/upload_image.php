<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/uploads.php';

// A szövegszerkesztőkbe (Rólunk, Webshop bevezető, Méretjelölés illusztráció,
// Kapcsolat megjegyzés, Új oldal) beszúrt képek ide töltődnek fel — valódi
// fájlként az uploads/ mappába, nem base64-ként a HTML-be ágyazva (ugyanaz a
// minta, mint a termékképeknél).
require_method('POST');
require_admin($mysqli);

$path = save_uploaded_image('image');
if ($path === null) error_response('No image uploaded');

respond(['path' => $path], 201);
