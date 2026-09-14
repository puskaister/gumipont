<?php
header('Content-Type: text/plain; charset=utf-8');
echo "PHP version: " . PHP_VERSION . "\n";
echo "mysqli client info: " . mysqli_get_client_info() . "\n";
echo "mysqlnd loaded: " . (extension_loaded('mysqlnd') ? 'yes' : 'no') . "\n";
echo "mysqli_stmt::get_result exists: " . (method_exists('mysqli_stmt', 'get_result') ? 'yes' : 'no') . "\n";
