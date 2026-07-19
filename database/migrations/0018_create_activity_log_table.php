<?php

declare(strict_types=1);

// General audit/activity log (blueprint §4.5). Deliberately not
// FK-constrained to admins/clients — the actor may be 'system' (no id),
// and log rows should survive even if the actor row is later deleted.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS activity_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_type ENUM('admin', 'client', 'system') NOT NULL,
            actor_id INT UNSIGNED NULL,
            action VARCHAR(100) NOT NULL,
            subject_type VARCHAR(100) NULL,
            subject_id INT UNSIGNED NULL,
            description VARCHAR(500) NOT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_actor (actor_type, actor_id),
            INDEX idx_subject (subject_type, subject_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
