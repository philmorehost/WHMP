<?php

declare(strict_types=1);

// Billable Items (blueprint §4.3) — an ad-hoc charge not tied to a
// product/service, e.g. converting resolved support work into a line
// item. `source_type`/`source_id` trace where it came from (a ticket
// today; nothing else generates these yet) without a hard FK to any one
// table. Once invoiced, `invoice_id` is set and it won't be picked up again.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS billable_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            invoice_id INT UNSIGNED NULL,
            source_type VARCHAR(50) NULL,
            source_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            CONSTRAINT fk_billable_items_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_billable_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
