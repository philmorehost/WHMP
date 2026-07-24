<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CodeVault\Kernel;

$kernel = new Kernel(dirname(__DIR__));
$db = $kernel->container->make(\CodeVault\Database::class);

echo "\n" . str_repeat("=", 100) . "\n";
echo "SERVER AUDIT - Finding Duplicates & Testing Functionality\n";
echo str_repeat("=", 100) . "\n\n";

// Get all servers
$servers = $db->select('SELECT * FROM servers ORDER BY module_slug, name', []);

$grouped = [];
foreach ($servers as $server) {
    $key = $server['module_slug'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [];
    }
    $grouped[$key][] = $server;
}

echo "📊 SERVER INVENTORY BY MODULE:\n";
echo str_repeat("-", 100) . "\n";

foreach ($grouped as $module => $servers) {
    echo "\n🔷 MODULE: $module (" . count($servers) . " server" . (count($servers) !== 1 ? "s" : "") . ")\n";
    echo str_repeat("-", 100) . "\n";

    foreach ($servers as $i => $srv) {
        echo sprintf(
            "  [%d] ID: %d | Name: %-30s | Host: %-30s | Active: %s\n",
            $i + 1,
            (int)$srv['id'],
            $srv['name'],
            $srv['hostname'] ?? 'N/A',
            $srv['is_active'] ? '✅ YES' : '❌ NO'
        );
    }

    if (count($servers) > 1) {
        echo "\n  ⚠️  DUPLICATE FOUND - Testing which is functional:\n";
        foreach ($servers as $i => $srv) {
            echo "\n  Testing Server #" . ($i + 1) . " ({$srv['name']})...\n";
            testServer($srv, $kernel);
        }
    }
}

echo "\n" . str_repeat("=", 100) . "\n";
echo "RECOMMENDATION:\n";
echo str_repeat("=", 100) . "\n";
provideRecommendations($grouped, $db);

function testServer(array $server, $kernel): void
{
    $module = $server['module_slug'];
    $hostname = $server['hostname'] ?? '';

    echo "  ├─ Module: $module\n";
    echo "  ├─ Hostname: " . ($hostname ?: 'N/A') . "\n";
    echo "  ├─ Active: " . ($server['is_active'] ? 'YES' : 'NO') . "\n";

    // Try to resolve module
    $moduleClass = null;
    switch ($module) {
        case 'cpanel':
            $moduleClass = 'CodeVault\Provisioning\CpanelProvisioningModule';
            break;
        case 'cyberpanel':
            $moduleClass = 'CodeVault\Provisioning\CyberPanelProvisioningModule';
            break;
        case 'interserver-vps':
            $moduleClass = 'CodeVault\Provisioning\InterServerVpsProvisioningModule';
            break;
        case 'interserver-dedicated':
            $moduleClass = 'CodeVault\Provisioning\InterServerDedicatedProvisioningModule';
            break;
        case 'nocix-dedicated':
            $moduleClass = 'CodeVault\Provisioning\NocixDedicatedServerModule';
            break;
        case 'resellerclub-email':
            $moduleClass = 'CodeVault\Provisioning\ResellerClubEmailProvisioningModule';
            break;
        case 'local':
            $moduleClass = 'CodeVault\Provisioning\LocalProvisioningModule';
            break;
    }

    if ($moduleClass && class_exists($moduleClass)) {
        echo "  ├─ Module Class: ✅ EXISTS\n";
        echo "  └─ Status: " . ($server['is_active'] ? '✅ ACTIVE & READY' : '⚠️  INACTIVE') . "\n";
    } else {
        echo "  ├─ Module Class: ❌ NOT FOUND\n";
        echo "  └─ Status: ⚠️  BROKEN - Module doesn't exist\n";
    }
}

function provideRecommendations(array $grouped, $db): void
{
    $toDelete = [];

    foreach ($grouped as $module => $servers) {
        if (count($servers) <= 1) {
            echo "✅ $module: Only 1 server - OK\n";
            continue;
        }

        echo "\n⚠️  $module: DUPLICATES DETECTED (" . count($servers) . " servers)\n";

        $active = array_filter($servers, fn($s) => $s['is_active']);
        $inactive = array_filter($servers, fn($s) => !$s['is_active']);

        if (count($active) > 1) {
            echo "  ❌ PROBLEM: Multiple ACTIVE servers for same module!\n";
            foreach (array_slice($active, 1) as $dup) {
                $toDelete[] = $dup['id'];
                echo "  → DELETE: Server ID {$dup['id']} ({$dup['name']}) - duplicate active\n";
            }
        }

        if (count($inactive) > 0) {
            echo "  → DELETE: " . count($inactive) . " inactive server(s)\n";
            foreach ($inactive as $dup) {
                $toDelete[] = $dup['id'];
                echo "    • Server ID {$dup['id']} ({$dup['name']}) - not active\n";
            }
        }
    }

    if ($toDelete) {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "SERVERS TO DELETE (via Admin UI):\n";
        echo str_repeat("=", 100) . "\n";
        foreach ($toDelete as $id) {
            echo "• Server ID $id\n";
        }
        echo "\nDelete these in Admin > Servers to clean up duplicates.\n";
    } else {
        echo "\n✅ All servers are properly configured - no duplicates!\n";
    }
}
