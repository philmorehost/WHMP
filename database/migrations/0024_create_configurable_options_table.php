<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS configurable_options (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            option_group_id INT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_group (option_group_id),
            CONSTRAINT fk_configurable_options_group FOREIGN KEY (option_group_id) REFERENCES configurable_option_groups(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
