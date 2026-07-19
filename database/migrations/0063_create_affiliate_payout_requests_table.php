<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS affiliate_payout_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            affiliate_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status ENUM('requested', 'approved', 'rejected', 'paid') NOT NULL DEFAULT 'requested',
            requested_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            INDEX idx_affiliate_payout_requests_affiliate (affiliate_id),
            CONSTRAINT fk_affiliate_payout_requests_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
