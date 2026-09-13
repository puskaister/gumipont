<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/settings.php';

require_method('POST');
require_admin($mysqli);

$body = json_input();
// Elutasítjuk, ha a body JSON tömb (nem objektum) — kompatibilis módon
// ellenőrizve (array_is_list csak PHP 8.1+-ban létezik, itt PHP 7.4-től működjön).
$isList = is_array($body) && $body !== [] && array_keys($body) === range(0, count($body) - 1);
if (!is_array($body) || $isList) {
    error_response('Body must be an object of settings key/value pairs');
}

foreach ($body as $key => $value) {
    set_setting($mysqli, (string) $key, $value);
}

respond(['settings' => get_all_settings($mysqli)]);
