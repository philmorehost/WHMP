<?php

declare(strict_types=1);

// R15 — Data retention/GDPR (blueprint §4.4 "Data retention/GDPR — pruning
// automation, export/erase requests", never built through R0-R14). A client
// requests export or erasure; an admin reviews and processes it — never
// fully automatic, since erasure has real legal/financial-retention
// implications a client's own click shouldn't be able to trigger alone.
// export_data holds the generated JSON export inline (LONGTEXT) rather than
// a filesystem path, so there's no export-file directory to manage/clean up
// separately — the request row IS the artifact.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS gdpr_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            type ENUM('export', 'erasure') NOT NULL,
            status ENUM('pending', 'completed', 'rejected') NOT NULL DEFAULT 'pending',
            export_data LONGTEXT NULL,
            admin_notes VARCHAR(500) NULL,
            processed_by_admin_id INT UNSIGNED NULL,
            processed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            INDEX idx_status (status),
            FOREIGN KEY (client_id) REFERENCES clients(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
