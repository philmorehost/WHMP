<?php

declare(strict_types=1);

// Attachments on support tickets (images + documents), shown inline in the
// thread with preview for images/PDFs. `stored_name` is a random on-disk
// name under storage/ticket_attachments/; `original_name` is what the
// uploader called it (shown in the UI). Rows cascade-delete with the ticket.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS ticket_attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            reply_id INT UNSIGNED NULL,
            uploaded_by ENUM('client', 'admin') NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_ticket (ticket_id),
            INDEX idx_reply (reply_id),
            CONSTRAINT fk_ta_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
