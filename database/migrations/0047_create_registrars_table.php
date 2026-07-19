<?php

declare(strict_types=1);

// One row per activated RegistrarModule (blueprint §3 Module SDK) — mirrors
// payment_gateways, not servers: a registrar is one config per module
// (API credentials), not a fleet of many hosts like provisioning servers.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS registrars (
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
        "INSERT INTO registrars (slug, name, config, is_enabled, sort_order, created_at, updated_at) VALUES ('local', 'Local (no external registry)', NULL, 1, 0, NOW(), NOW())",
    ],
];
