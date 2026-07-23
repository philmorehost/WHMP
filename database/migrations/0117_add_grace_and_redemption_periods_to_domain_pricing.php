<?php

declare(strict_types=1);

// Add grace period and redemption period columns to domain_pricing table
// to support WHMCS domain lifecycle management

return [
    'up' => [
        "ALTER TABLE domain_pricing ADD COLUMN grace_period_days INT UNSIGNED DEFAULT 30 AFTER renew_price",
        "ALTER TABLE domain_pricing ADD COLUMN redemption_period_days INT UNSIGNED DEFAULT 30 AFTER grace_period_days",
        "ALTER TABLE domain_pricing ADD COLUMN redemption_fee DECIMAL(10,2) DEFAULT 0.00 AFTER redemption_period_days",
    ],
];
