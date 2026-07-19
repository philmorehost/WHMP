<?php

declare(strict_types=1);

// Per-option pricing varies by billing cycle (blueprint §4.2), same cycle
// enum as product_pricing so cart totals can be computed uniformly.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS configurable_option_pricing (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            option_id INT UNSIGNED NOT NULL,
            billing_cycle ENUM('one_time', 'monthly', 'quarterly', 'semi_annually', 'annually', 'biennially', 'triennially') NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            UNIQUE KEY uq_option_cycle (option_id, billing_cycle),
            CONSTRAINT fk_configurable_option_pricing_option FOREIGN KEY (option_id) REFERENCES configurable_options(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
