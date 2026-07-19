<?php

declare(strict_types=1);

// Every outbound email lands a row here regardless of send outcome —
// this is what "My Emails" (client area) and the admin Email Log both
// read from (blueprint §4.1/§4.3).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS email_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            to_email VARCHAR(191) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            template_key VARCHAR(100) NULL,
            client_id INT UNSIGNED NULL,
            status ENUM('queued', 'sent', 'failed') NOT NULL DEFAULT 'queued',
            error TEXT NULL,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            INDEX idx_client (client_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
