<?php

declare(strict_types=1);

// `client_id` is nullable and `email` is a denormalized fallback — a
// ticket piped in from an email address that doesn't match any client
// still needs somewhere to land (blueprint §4.4 "IMAP/POP email piping").
// `last_reply_at`/`last_reply_by` drive both auto-close-on-inactivity and
// the "customer-reply" status transition without re-deriving it from the
// replies table on every read.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS tickets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NULL,
            email VARCHAR(191) NOT NULL,
            department_id INT UNSIGNED NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status ENUM('open', 'answered', 'customer-reply', 'closed') NOT NULL DEFAULT 'open',
            priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
            assigned_admin_id INT UNSIGNED NULL,
            satisfaction_rating TINYINT UNSIGNED NULL,
            last_reply_at DATETIME NULL,
            last_reply_by ENUM('client', 'admin') NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            INDEX idx_status (status),
            INDEX idx_department (department_id),
            CONSTRAINT fk_tickets_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
            CONSTRAINT fk_tickets_department FOREIGN KEY (department_id) REFERENCES departments(id),
            CONSTRAINT fk_tickets_assigned_admin FOREIGN KEY (assigned_admin_id) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
