<?php

declare(strict_types=1);

// Localization (blueprint §5): available client+admin languages. File-based
// translation catalogs (resources/lang/{code}.php) hold the bulk of
// strings; this table is just the switchable language list plus RTL flag.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS languages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(10) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            is_rtl TINYINT(1) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        "INSERT INTO languages (code, name, is_rtl, is_default, is_active, created_at, updated_at) VALUES
            ('en', 'English', 0, 1, 1, NOW(), NOW()),
            ('es', 'Español', 0, 0, 1, NOW(), NOW()),
            ('ar', 'العربية', 1, 0, 1, NOW(), NOW())",
    ],
];
