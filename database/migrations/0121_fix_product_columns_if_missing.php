<?php

declare(strict_types=1);

// Fallback migration to ensure all required product columns exist.
// If migration 0120 didn't run (older MySQL without IF NOT EXISTS support),
// this migration will add any missing columns one by one.

return [
    'up' => [
        // These will fail silently if column already exists (using catch-all approach)
        "ALTER TABLE products ADD COLUMN whm_package_name VARCHAR(191) NULL AFTER server_group_id",
        "ALTER TABLE products ADD COLUMN pay_type VARCHAR(20) NOT NULL DEFAULT 'paid' AFTER type",
        "ALTER TABLE products ADD COLUMN free_duration_type VARCHAR(50) NOT NULL DEFAULT 'lifetime' AFTER require_domain",
        "ALTER TABLE products ADD COLUMN free_duration_days INT NULL AFTER free_duration_type",
    ],
];
