<?php

declare(strict_types=1);

// Cloudflare Email Routing is optional and Free-plan only. Destination addresses
// are account-wide in Cloudflare; local ownership is deliberately unique across
// WHMP clients/resellers so one tenant can never claim another tenant's address.
return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS cloudflare_email_destinations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            reseller_id INT UNSIGNED NULL,
            cf_destination_id VARCHAR(64) NULL,
            email VARCHAR(191) NOT NULL,
            verified_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_cf_email_destination_email (email),
            UNIQUE KEY uniq_cf_email_destination_remote (cf_destination_id),
            KEY idx_cf_email_destination_owner (client_id, reseller_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS cloudflare_email_routes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            zone_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            reseller_id INT UNSIGNED NULL,
            cf_rule_id VARCHAR(64) NOT NULL,
            local_part VARCHAR(64) NOT NULL,
            destination_id INT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_cf_email_route_remote (zone_id, cf_rule_id),
            UNIQUE KEY uniq_cf_email_route_alias (zone_id, local_part),
            KEY idx_cf_email_route_owner (client_id, reseller_id),
            KEY idx_cf_email_route_destination (destination_id),
            CONSTRAINT fk_cf_email_route_destination FOREIGN KEY (destination_id)
                REFERENCES cloudflare_email_destinations (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
