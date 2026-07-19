<?php

declare(strict_types=1);

// Configurable Option Groups (blueprint §4.2 — called out as its own
// sub-spec): shared across products, e.g. "Extra Resources" attached to
// several hosting plans at once.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS configurable_option_groups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS product_configurable_option_groups (
            product_id INT UNSIGNED NOT NULL,
            option_group_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (product_id, option_group_id),
            CONSTRAINT fk_pcog_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT fk_pcog_group FOREIGN KEY (option_group_id) REFERENCES configurable_option_groups(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
