<?php
require __DIR__ . '/vendor/autoload.php';
$app = new \CodeVault\Kernel(__DIR__);
try {
    $ref = new ReflectionProperty($app, 'container');
    $ref->setAccessible(true);
    $container = $ref->getValue($app);
    $db = $container->make(\CodeVault\Database::class);
    $tables = $db->select("SHOW TABLES");
    print_r($tables);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
