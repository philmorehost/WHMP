<?php

declare(strict_types=1);

// One row per IP that BruteGuard has ever taken an action on: a tiered
// blacklist block (day/week/month/year — blueprint §5) or an auto/manual
// whitelist entry (the green "King" badge). `clean_session_count` tracks
// consecutive successful logins toward the 5-clean-sessions auto-whitelist.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS security_ip_rules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            policy ENUM('blacklisted', 'whitelisted') NULL,
            tier ENUM('day', 'week', 'month', 'year') NULL,
            source ENUM('auto', 'manual') NOT NULL DEFAULT 'auto',
            reason VARCHAR(255) NULL,
            admin_id INT UNSIGNED NULL,
            clean_session_count INT UNSIGNED NOT NULL DEFAULT 0,
            block_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            INDEX idx_policy (policy)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
