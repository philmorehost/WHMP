<?php

declare(strict_types=1);

// One row per activated GatewayModule instance (blueprint §3 Module SDK).
// `config` is a JSON bag of the module's own config fields
// (ProvisioningModule::configOptions()-style) — API keys etc. Encryption
// of sensitive config values is a follow-up once a real API-key-bearing
// gateway (not just Manual) is added.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS payment_gateways (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(191) NOT NULL,
            config JSON NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
