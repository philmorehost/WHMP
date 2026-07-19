<?php

declare(strict_types=1);

// Affiliate engine (blueprint §4.4): one row per client who's opted into
// the affiliate program. `code` is the referral token in `?ref=` links;
// `balance` is the running unpaid-commission total, decremented as
// payout requests are marked paid.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS affiliates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            code VARCHAR(32) NOT NULL,
            status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
            commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
            balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_affiliates_client (client_id),
            UNIQUE KEY uniq_affiliates_code (code),
            CONSTRAINT fk_affiliates_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
