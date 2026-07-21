<?php
require __DIR__ . '/vendor/autoload.php';
$app = new \CodeVault\Kernel(__DIR__);

try {
    $ref = new ReflectionProperty($app, 'container');
    $ref->setAccessible(true);
    $container = $ref->getValue($app);
    $service = $container->make(\CodeVault\Import\WhmcsImportService::class);
    $res = $service->import([
        'host' => '173.208.213.194',
        'port' => 3306,
        'database' => 'prnhost_whmcsbill',
        'username' => 'prnhost_whmcsbill',
        'password' => 'some_password',
    ], false);
    print_r($res);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
