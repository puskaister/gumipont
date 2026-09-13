<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/settings.php';

require_method('GET');

respond(['settings' => get_all_settings($mysqli)]);
