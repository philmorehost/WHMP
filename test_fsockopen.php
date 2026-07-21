<?php
require __DIR__ . '/vendor/autoload.php';
$app = new \CodeVault\Kernel(__DIR__);

try {
    $disabled = ini_get('disable_functions');
    echo "DISABLED FUNCTIONS: " . $disabled . "\n";
    $exists = function_exists('fsockopen');
    echo "FSOCKOPEN EXISTS: " . ($exists ? 'YES' : 'NO') . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
