<?php

declare(strict_types=1);

// Notification providers (blueprint §5): Slack incoming-webhooks and
// generic outbound webhooks, each subscribed to a set of hook-point event
// names. `events` is a JSON array of hook point names (e.g. "order.placed").

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS notification_endpoints (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type ENUM('slack', 'webhook') NOT NULL,
            name VARCHAR(191) NOT NULL,
            url VARCHAR(500) NOT NULL,
            secret VARCHAR(255) NULL,
            events TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
