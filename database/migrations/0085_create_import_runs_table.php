<?php

declare(strict_types=1);

// R16 — Migration/Import engine (blueprint §4.4 "general CSV/JSON importer
// for clients, services, invoices, and transactions", never built through
// R0-R15; scoped to clients first, the foundational entity everything else
// attaches to). One row per upload attempt — the audit trail an admin needs
// to know what a given CSV actually did, since a bulk import is exactly the
// kind of action you want a paper trail for after the fact.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS import_runs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NOT NULL,
            entity_type VARCHAR(32) NOT NULL DEFAULT 'clients',
            filename VARCHAR(255) NOT NULL,
            total_rows INT UNSIGNED NOT NULL,
            imported_count INT UNSIGNED NOT NULL,
            skipped_count INT UNSIGNED NOT NULL,
            errors TEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
