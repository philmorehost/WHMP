<?php

declare(strict_types=1);

return [
    'up' => [
        "ALTER TABLE orders ADD COLUMN is_cancelled BOOLEAN DEFAULT 0 AFTER status",
        "ALTER TABLE orders ADD COLUMN cancelled_at DATETIME NULL AFTER is_cancelled",
        "ALTER TABLE orders ADD COLUMN cancellation_reason TEXT NULL AFTER cancelled_at",

        "ALTER TABLE invoices ADD COLUMN is_cancelled BOOLEAN DEFAULT 0 AFTER status",
        "ALTER TABLE invoices ADD COLUMN cancelled_at DATETIME NULL AFTER is_cancelled",
        "ALTER TABLE invoices ADD COLUMN cancellation_reason TEXT NULL AFTER cancelled_at",
    ],
];
