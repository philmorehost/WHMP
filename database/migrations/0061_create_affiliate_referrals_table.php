<?php

declare(strict_types=1);

// One row per successfully referred client — a client can only be
// attributed to a single affiliate (their first referral), hence the
// unique key on referred_client_id.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS affiliate_referrals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            affiliate_id INT UNSIGNED NOT NULL,
            referred_client_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_affiliate_referrals_client (referred_client_id),
            INDEX idx_affiliate_referrals_affiliate (affiliate_id),
            CONSTRAINT fk_affiliate_referrals_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
            CONSTRAINT fk_affiliate_referrals_client FOREIGN KEY (referred_client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
