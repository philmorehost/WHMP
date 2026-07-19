<?php

declare(strict_types=1);

// Same locked-rate pattern as orders (0067) — an invoice's currency_rate
// is fixed at generation time so reprinting/paying it later never shows
// a different converted total than what the client originally saw.

return [
    'up' => [
        'ALTER TABLE invoices ADD COLUMN currency_id INT UNSIGNED NULL AFTER total',
        'ALTER TABLE invoices ADD COLUMN currency_rate DECIMAL(10,4) NOT NULL DEFAULT 1.0000 AFTER currency_id',
        'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE SET NULL',
    ],
];
