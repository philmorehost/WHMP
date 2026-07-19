<?php

declare(strict_types=1);

// Pricing is snapshotted onto the row at order time (unit_price, setup_fee,
// configurable_options JSON) rather than re-joined from products/options —
// so a later price change never rewrites history.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS order_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            product_name VARCHAR(191) NOT NULL,
            billing_cycle ENUM('one_time', 'monthly', 'quarterly', 'semi_annually', 'annually', 'biennially', 'triennially') NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL,
            setup_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            configurable_options JSON NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_order (order_id),
            CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
