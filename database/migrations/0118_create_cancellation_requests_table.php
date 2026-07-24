<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS cancellation_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            cancellation_type ENUM('immediate', 'due_date') NOT NULL DEFAULT 'immediate',
            cancel_date DATE NULL COMMENT 'For due_date type, the date to cancel on',
            reason TEXT NOT NULL,
            admin_notes TEXT NULL,
            status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending',
            reviewed_by INT UNSIGNED NULL COMMENT 'Admin ID who reviewed the request',
            reviewed_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL,
            INDEX (status),
            INDEX (client_id),
            INDEX (service_id),
            INDEX (cancel_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
