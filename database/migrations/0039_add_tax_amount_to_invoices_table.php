<?php

declare(strict_types=1);

// Tax is also recorded as its own invoice_items row (so it's visible in
// the line-item breakdown), but this column lets reports/UI read the tax
// total directly instead of pattern-matching item descriptions.

return [
    'up' => [
        'ALTER TABLE invoices ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subtotal',
    ],
];
