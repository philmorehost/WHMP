<?php
require __DIR__ . '/vendor/autoload.php';
$app = new \CodeVault\Kernel(__DIR__);
try {
    $ref = new ReflectionProperty($app, 'container');
    $ref->setAccessible(true);
    $container = $ref->getValue($app);
    $db = $container->make(\CodeVault\Database::class);
    $runs = $db->select("SELECT * FROM import_runs ORDER BY id DESC LIMIT 5");
    print_r($runs);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
