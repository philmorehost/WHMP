<?php

declare(strict_types=1);

// A product can be priced on any subset of these cycles (blueprint §4.4
// "multi-cycle: monthly->triennial"); a cycle with no row simply isn't
// offered for that product.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS product_pricing (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            billing_cycle ENUM('one_time', 'monthly', 'quarterly', 'semi_annually', 'annually', 'biennially', 'triennially') NOT NULL,
            setup_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            price DECIMAL(10,2) NOT NULL,
            UNIQUE KEY uq_product_cycle (product_id, billing_cycle),
            CONSTRAINT fk_product_pricing_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
