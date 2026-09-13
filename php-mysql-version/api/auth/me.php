<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_method('GET');

$user = require_auth($mysqli);
respond(['user' => $user]);
