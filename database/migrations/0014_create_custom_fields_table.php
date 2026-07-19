<?php

declare(strict_types=1);

// Custom Client/Product Fields (blueprint §4.3 Setup/Configuration).
// `field_for` scopes a definition to where it renders; only 'client' has
// anywhere to attach a value until products exist (R4+).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS custom_fields (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            field_for ENUM('client', 'product') NOT NULL DEFAULT 'client',
            name VARCHAR(191) NOT NULL,
            type ENUM('text', 'textarea', 'dropdown', 'checkbox', 'password') NOT NULL DEFAULT 'text',
            options TEXT NULL,
            required TINYINT(1) NOT NULL DEFAULT 0,
            admin_only TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_field_for (field_for)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
