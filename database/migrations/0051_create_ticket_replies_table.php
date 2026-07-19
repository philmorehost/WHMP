<?php

declare(strict_types=1);

// `is_private` rows are admin-only notes on the thread — never shown to
// the client, never exported via any client-facing endpoint. `author_id`
// is a client_id or admin_id depending on author_type (no FK — could point
// to either table).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS ticket_replies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            author_type ENUM('client', 'admin') NOT NULL,
            author_id INT UNSIGNED NULL,
            author_name VARCHAR(191) NOT NULL,
            message TEXT NOT NULL,
            is_private TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_ticket (ticket_id),
            CONSTRAINT fk_ticket_replies_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
