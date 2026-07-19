<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT UNSIGNED NOT NULL,
            gateway_slug VARCHAR(50) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status ENUM('completed', 'refunded', 'failed') NOT NULL DEFAULT 'completed',
            gateway_transaction_id VARCHAR(191) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_invoice (invoice_id),
            CONSTRAINT fk_transactions_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
