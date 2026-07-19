<?php

declare(strict_types=1);

// One row per commission accrual event — fired when a referred client's
// invoice is paid (blueprint §4.4 "commission accrual"). Kept as an
// append-only ledger, same idiom as client_credit_ledger, rather than
// just incrementing affiliates.balance blind, so payouts are auditable.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS affiliate_commissions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            affiliate_id INT UNSIGNED NOT NULL,
            invoice_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            INDEX idx_affiliate_commissions_affiliate (affiliate_id),
            CONSTRAINT fk_affiliate_commissions_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
            CONSTRAINT fk_affiliate_commissions_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
