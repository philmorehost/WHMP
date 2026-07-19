<?php

declare(strict_types=1);

// Promo/coupon codes (blueprint §4.2 "Promo codes"). A code applies as
// either a percentage or a flat amount off the cart subtotal (never setup
// fees), optionally gated by an active window, a redemption cap, and a
// minimum order amount. `redemption_count` is incremented inside the same
// transaction as order placement, matching how `product_pricing`/`domains`
// track their own mutable counters rather than deriving them from a
// separate ledger table.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS promotions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            type ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
            value DECIMAL(10,2) NOT NULL,
            max_redemptions INT UNSIGNED NULL,
            redemption_count INT UNSIGNED NOT NULL DEFAULT 0,
            min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            starts_at DATE NULL,
            expires_at DATE NULL,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
