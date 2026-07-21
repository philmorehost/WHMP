<?php
require __DIR__ . '/vendor/autoload.php';

$app = new \CodeVault\Kernel(__DIR__);

try {
    $ref = new ReflectionProperty($app, 'container');
    $ref->setAccessible(true);
    $container = $ref->getValue($app);
    
    $controller = $container->make(\CodeVault\Import\WhmcsImportController::class);
    echo "SUCCESS\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
