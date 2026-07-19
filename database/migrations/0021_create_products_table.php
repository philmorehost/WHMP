<?php

declare(strict_types=1);

// `stock_quantity` NULL = unlimited (blueprint §4.2 oversell protection).
// Provisioning-module linkage (which server module fulfills this product)
// lands with the provisioning engine in R6 — this is catalogue + pricing only.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_group_id INT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            status ENUM('active', 'hidden') NOT NULL DEFAULT 'active',
            stock_quantity INT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_group (product_group_id),
            CONSTRAINT fk_products_group FOREIGN KEY (product_group_id) REFERENCES product_groups(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
