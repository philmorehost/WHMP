<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS cancellation_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_id INT UNSIGNED NOT NULL,
            type ENUM('immediate', 'end_of_period') NOT NULL,
            reason TEXT NULL,
            status ENUM('pending', 'processed') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_service_pending (service_id, status),
            CONSTRAINT fk_cancellation_requests_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
