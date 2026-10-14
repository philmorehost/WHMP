<?php

declare(strict_types=1);

/**
 * Lists the Nocix server records, which API host each one uses, and whether the
 * API credentials answer. Run on the live server:
 *   php bin/check-nocix-config.php
 *
 * The API host is https://my.nocix.net/api (my.wholesaleinternet.net for wholesale
 * accounts, picked when the record's hostname names it). This script used to call
 * https://manage.nocix.net "correct", a host that does not exist.
 */

require __DIR__ . '/../vendor/autoload.php';

use CodeVault\Kernel;
use CodeVault\Provisioning\NocixDedicatedServerModule;

$kernel = new Kernel(dirname(__DIR__));
$db = $kernel->container->make(\CodeVault\Database::class);
$module = $kernel->container->make(NocixDedicatedServerModule::class);

$nocixServers = $db->select(
    'SELECT * FROM servers WHERE module_slug LIKE ?',
    ['%nocix%']
);

echo "\n=== NOCIX SERVER CONFIGURATION ===\n\n";

foreach ($nocixServers as $srv) {
    $host = str_contains(strtolower((string) ($srv['hostname'] ?? '')), 'wholesaleinternet') ? 'my.wholesaleinternet.net' : 'my.nocix.net';

    echo 'Server ID: ' . $srv['id'] . "\n";
    echo 'Name: ' . $srv['name'] . "\n";
    echo 'Module: ' . $srv['module_slug'] . "\n";
    echo 'Active: ' . (!empty($srv['active']) ? 'YES' : 'NO') . "\n";
    echo "API host used: https://{$host}/api\n";
    echo 'API username: ' . (trim((string) ($srv['api_username'] ?? '')) !== '' ? 'set' : 'MISSING') . "\n";
    echo 'API token: ' . (trim((string) ($srv['api_token'] ?? '')) !== '' ? 'set' : 'MISSING') . "\n";

    $result = $module->testConnection(['server' => $srv]);
    echo 'Connection: ' . ($result['success'] ? '✅ ' : '❌ ') . $result['message'] . "\n\n";
}

if ($nocixServers === []) {
    echo "No Nocix servers found.\n";
}
