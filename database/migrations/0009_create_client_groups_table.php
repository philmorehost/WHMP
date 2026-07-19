<?php

declare(strict_types=1);

// Client Groups (blueprint §4.3 Setup/Configuration) — used for pricing
// overrides and segmentation once products exist (R4+); clients can belong
// to a group from day one.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_groups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
