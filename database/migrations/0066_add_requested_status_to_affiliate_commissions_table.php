<?php

declare(strict_types=1);

// AffiliateCommissionRepository's pending -> requested -> paid lifecycle
// needs the middle state the original ENUM was missing.

return [
    'up' => [
        "ALTER TABLE affiliate_commissions MODIFY COLUMN status ENUM('pending', 'requested', 'paid') NOT NULL DEFAULT 'pending'",
    ],
];
