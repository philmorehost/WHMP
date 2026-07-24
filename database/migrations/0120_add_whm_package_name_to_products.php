<?php

declare(strict_types=1);

return [
    'up' => [
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS whm_package_name VARCHAR(191) NULL AFTER server_group_id",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS pay_type VARCHAR(20) NOT NULL DEFAULT 'paid' AFTER type",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS free_duration_type VARCHAR(50) NOT NULL DEFAULT 'lifetime' AFTER require_domain",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS free_duration_days INT NULL AFTER free_duration_type",
    ],
];
