<?php

declare(strict_types=1);

// R11 multi-currency: orders now record which currency the client was
// shown at checkout and the exchange rate locked at that moment, so a
// historical order's displayed total never drifts as rates change later.
// NULL currency_id means "base/default currency" — no conversion needed,
// keeping every pre-R11 order valid without a backfill.

return [
    'up' => [
        'ALTER TABLE orders ADD COLUMN currency_id INT UNSIGNED NULL AFTER total',
        'ALTER TABLE orders ADD COLUMN currency_rate DECIMAL(10,4) NOT NULL DEFAULT 1.0000 AFTER currency_id',
        'ALTER TABLE orders ADD CONSTRAINT fk_orders_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE SET NULL',
    ],
];
