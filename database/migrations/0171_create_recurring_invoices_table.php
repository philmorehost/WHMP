<?php

declare(strict_types=1);

// Standalone recurring invoices (admin ad-hoc billing, WHMCS-style). The
// admin can mark an invoice raised at /admin/invoices/create as recurring;
// this table stores the template (client, line items, cycle, currency) and
// the cron RecurringInvoiceJob generates a fresh invoice each cycle. Each
// generated invoice records which recurring_invoices row produced it
// (invoices.recurring_invoice_id), which the generator uses as its
// idempotency guard — the same pattern RecurringBillingService uses for
// services.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS recurring_invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            currency_id INT UNSIGNED NULL,
            currency_rate DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
            billing_cycle ENUM('monthly', 'quarterly', 'semi_annually', 'annually', 'biennially', 'triennially') NOT NULL,
            items JSON NOT NULL,
            amount DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            due_in_days INT UNSIGNED NOT NULL DEFAULT 0,
            next_due_date DATE NOT NULL,
            last_generated_at DATETIME NULL,
            last_invoice_id INT UNSIGNED NULL,
            status ENUM('active', 'paused', 'cancelled') NOT NULL DEFAULT 'active',
            created_by_admin_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_recurring_client (client_id),
            INDEX idx_recurring_status_due (status, next_due_date),
            INDEX idx_recurring_last_invoice (last_invoice_id),
            CONSTRAINT fk_recurring_invoices_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        // Link each generated invoice back to the recurring_invoices row that
        // produced it (idempotency + traceability from the invoice detail page).
        "ALTER TABLE invoices ADD COLUMN recurring_invoice_id INT UNSIGNED NULL AFTER domain_id",
        "ALTER TABLE invoices ADD INDEX idx_invoices_recurring (recurring_invoice_id)",
        <<<'SQL'
        ALTER TABLE invoices
            ADD CONSTRAINT fk_invoices_recurring FOREIGN KEY (recurring_invoice_id)
            REFERENCES recurring_invoices (id) ON DELETE SET NULL
        SQL,
    ],
];
