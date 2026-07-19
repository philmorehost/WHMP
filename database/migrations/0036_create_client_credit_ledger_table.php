<?php

declare(strict_types=1);

// Append-only ledger — a client's credit balance is SUM(amount), never a
// stored/denormalized column, so it can't drift out of sync. Positive
// amount = credit added (refund-to-credit, manual grant); negative =
// credit spent (applied to an invoice).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_credit_ledger (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            invoice_id INT UNSIGNED NULL,
            admin_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            CONSTRAINT fk_credit_ledger_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_credit_ledger_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
