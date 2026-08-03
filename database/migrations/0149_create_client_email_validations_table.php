<?php

declare(strict_types=1);

// Admin-triggered "scan all client emails" report (blueprint: several
// marketing sends were bouncing and the bounce notices were landing as
// support tickets via mail piping — this lets an admin proactively see
// which client emails are actually deliverable instead of finding out one
// bounce-ticket at a time). One row per client, upserted on every scan —
// this is a current-state report, not a history log.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_email_validations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            email VARCHAR(191) NOT NULL,
            is_valid TINYINT(1) NOT NULL,
            reason VARCHAR(255) NULL,
            recent_failures INT UNSIGNED NOT NULL DEFAULT 0,
            checked_at DATETIME NOT NULL,
            UNIQUE KEY uniq_client (client_id),
            CONSTRAINT fk_client_email_validations_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
