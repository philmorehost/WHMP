<?php

declare(strict_types=1);

// An admin can place an order that records a service or domain that already
// exists (e.g. migrated in from another system) without raising a new
// invoice. Those orders are marked so the admin order page can label them and
// background jobs (renewals, billable items) never try to bill a settled
// historical order.

return [
    'up' => [
        "ALTER TABLE orders ADD COLUMN no_invoice BOOLEAN DEFAULT 0 AFTER is_cancelled",
    ],
];
