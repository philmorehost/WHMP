<?php

declare(strict_types=1);

// Discount is applied at cart-pricing time (CartService::priced()), before
// tax is calculated on the resulting total — these columns are for
// reporting/display (so an order/invoice can show the discount that was
// applied without re-deriving it from the promo code), not for computing
// the total itself. `promotion_code` is a plain snapshot string (not an FK)
// since a promotion can be edited/deleted later but a historical
// order/invoice must keep showing the code that was actually used.

return [
    'up' => [
        'ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total',
        'ALTER TABLE orders ADD COLUMN promotion_code VARCHAR(50) NULL AFTER discount_amount',
        'ALTER TABLE invoices ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_amount',
        'ALTER TABLE invoices ADD COLUMN promotion_code VARCHAR(50) NULL AFTER discount_amount',
    ],
];
