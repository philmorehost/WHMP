<?php

declare(strict_types=1);

// MarketConnect-style upsell (blueprint §4.4): any existing product can
// be flagged as an in-cart upsell offer (SSL, email, security add-ons)
// with a short pitch line — no separate product type, just a flag on
// the catalog entry already there.

return [
    'up' => [
        'ALTER TABLE products ADD COLUMN is_upsell TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
        'ALTER TABLE products ADD COLUMN upsell_pitch VARCHAR(255) NULL AFTER is_upsell',
    ],
];
