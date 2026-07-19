<?php

declare(strict_types=1);

// `module_slug` selects which ProvisioningModule handles this server
// ('local', 'cpanel', 'cyberpanel', ...) — mirrors how payment_gateways.slug
// selects a GatewayModule. Credentials are plaintext for now, same known
// gap as payment_gateways.config (§5 doc note: encrypt secrets at rest is
// a follow-up once there's a real key-management story, not a v1 blocker).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS servers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            server_group_id INT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            hostname VARCHAR(191) NOT NULL,
            module_slug VARCHAR(50) NOT NULL,
            api_username VARCHAR(191) NULL,
            api_token VARCHAR(255) NULL,
            api_port INT UNSIGNED NULL,
            use_ssl TINYINT(1) NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_group (server_group_id),
            CONSTRAINT fk_servers_group FOREIGN KEY (server_group_id) REFERENCES server_groups(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
