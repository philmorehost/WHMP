<?php

declare(strict_types=1);

// Fraud review columns (blueprint §4.4 fraud engine). `orders.status`
// already has a 'fraud' value from R4's forward-looking schema — these
// columns carry the score/reasons/reviewer trail behind that status.

return [
    'up' => [
        'ALTER TABLE orders ADD COLUMN fraud_score DECIMAL(5,2) NULL AFTER total',
        'ALTER TABLE orders ADD COLUMN fraud_reasons TEXT NULL AFTER fraud_score',
        'ALTER TABLE orders ADD COLUMN fraud_reviewed_by INT UNSIGNED NULL AFTER fraud_reasons',
        'ALTER TABLE orders ADD COLUMN fraud_reviewed_at DATETIME NULL AFTER fraud_reviewed_by',
    ],
];
