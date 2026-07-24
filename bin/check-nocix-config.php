<?php
require __DIR__ . '/../vendor/autoload.php';

use CodeVault\Kernel;

$kernel = new Kernel(dirname(__DIR__));
$db = $kernel->container->make(\CodeVault\Database::class);

$nocixServers = $db->select(
    'SELECT id, name, hostname, is_active, module_slug FROM servers WHERE module_slug LIKE ?',
    ['%nocix%']
);

echo "\n=== NOCIX SERVER CONFIGURATION ===\n\n";

foreach ($nocixServers as $srv) {
    echo "Server ID: " . $srv['id'] . "\n";
    echo "Name: " . $srv['name'] . "\n";
    echo "Module: " . $srv['module_slug'] . "\n";
    echo "Hostname: " . ($srv['hostname'] ?? 'NOT SET') . "\n";
    echo "Active: " . ($srv['is_active'] ? 'YES' : 'NO') . "\n";
    echo "Status: ";
    
    if ($srv['hostname'] === 'https://manage.nocix.net') {
        echo "✅ CORRECT\n";
    } elseif (strpos($srv['hostname'] ?? '', 'my.nocix.net') !== false) {
        echo "❌ WRONG - Using my.nocix.net (documentation only)\n";
    } elseif (strpos($srv['hostname'] ?? '', 'nocix') === false) {
        echo "❌ WRONG - Not a Nocix URL\n";
    } else {
        echo "⚠️  UNCLEAR - " . $srv['hostname'] . "\n";
    }
    echo "\n";
}

if (empty($nocixServers)) {
    echo "No Nocix servers found.\n";
}
