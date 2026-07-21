<?php
require __DIR__ . '/vendor/autoload.php';

$app = new \CodeVault\Kernel(__DIR__);

try {
    $service = $app->container()->make(\CodeVault\Import\WhmcsImportService::class);
    $result = $service->import([
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'whmcs_db',
        'username' => 'root',
        'password' => '',
        'prefix' => 'tbl',
    ], false);
    
    echo "SUCCESS\n";
    print_r($result);
} catch (\Throwable $e) {
    echo "FATAL ERROR\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
