<?php

declare(strict_types=1);

// R18 — Credit Notes (blueprint §4.3 Billing menu "Credit & Debit Notes",
// never built through R0-R17). Account credit granting existed only as a
// bare client_credit_ledger row with a free-text reason (admin Grant
// Credit form) — no formal document, no PDF, no line-item breakdown.
// total is always computed from credit_note_items by CreditNoteService,
// never trusted as separately-submitted input, so the document and the
// ledger entry it produces can never disagree with the line items shown.
// Deliberately immutable once issued — no status/void column — matching
// how invoices' own cancel() is one-directional; see CreditNoteService
// docblock for the reasoning.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS credit_notes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            invoice_id INT UNSIGNED NULL,
            reason VARCHAR(255) NOT NULL,
            total DECIMAL(10,2) NOT NULL,
            currency_id INT UNSIGNED NULL,
            currency_rate DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
            created_by_admin_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            INDEX idx_invoice (invoice_id),
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
            FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS credit_note_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            credit_note_id INT UNSIGNED NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            INDEX idx_credit_note (credit_note_id),
            FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
