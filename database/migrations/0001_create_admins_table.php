<?php

declare(strict_types=1);

// Minimal bootstrap admin table for the installer (Stage 3). Full
// roles/permissions matrix lands with Staff Management in R3 — this is
// just enough to create the first login-capable account.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS admins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            email VARCHAR(191) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
