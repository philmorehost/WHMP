<?php

declare(strict_types=1);

// R23 — Quotes (blueprint §4.1 client-area "My Quotes", §4.3 admin Billing
// menu). Named in the blueprint since R0 but never built — R11's PDF-engine
// task explicitly scoped Quotes out ("Quotes and credit notes are not
// separate entities in this build"), and unlike Credit Notes (picked back
// up in R18) Quotes were never revisited until now. total is always
// computed from quote_items by QuoteService, never trusted as separately-
// submitted input, same discipline as credit_notes. invoice_id is set only
// once a quote is accepted and converted — a quote and the invoice it
// produces are two distinct documents, not the same row reused.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS quotes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status ENUM('draft', 'sent', 'accepted', 'declined', 'expired') NOT NULL DEFAULT 'draft',
            valid_until DATE NULL,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency_id INT UNSIGNED NULL,
            currency_rate DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
            invoice_id INT UNSIGNED NULL,
            created_by_admin_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            INDEX idx_status (status),
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE SET NULL,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS quote_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            quote_id INT UNSIGNED NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            INDEX idx_quote (quote_id),
            FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
