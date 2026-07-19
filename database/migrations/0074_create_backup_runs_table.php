<?php

declare(strict_types=1);

// Backup hooks (blueprint §5): a history log of DB+storage backup runs,
// triggered manually from admin or by cron.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS backup_runs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
            file_path VARCHAR(500) NULL,
            size_bytes BIGINT UNSIGNED NULL,
            error TEXT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
