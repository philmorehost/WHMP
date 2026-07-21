<?php

declare(strict_types=1);

// Stored, reusable payment methods for automated recurring billing (R5
// auto-charge). PCI scope: we NEVER store a raw PAN here — `token` holds the
// gateway's own reusable reference (e.g. a Paystack authorization_code), and
// only the display-safe brand/last4/expiry are kept for the client to
// recognise their card. A client has at most one default per gateway; the
// auto-charge job charges the default when an invoice comes due.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_payment_methods (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            gateway_slug VARCHAR(64) NOT NULL,
            token VARCHAR(255) NOT NULL,
            card_brand VARCHAR(40) NULL,
            card_last4 VARCHAR(4) NULL,
            card_exp_month VARCHAR(2) NULL,
            card_exp_year VARCHAR(4) NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            UNIQUE KEY uniq_client_gateway_token (client_id, gateway_slug, token),
            CONSTRAINT fk_cpm_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
