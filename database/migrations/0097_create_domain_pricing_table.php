<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS domain_pricing (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tld VARCHAR(30) NOT NULL UNIQUE,
            registrar_slug VARCHAR(50) NOT NULL,
            register_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            transfer_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            renew_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        // Seed default TLDs with standard pricing
        "INSERT INTO domain_pricing (tld, registrar_slug, register_price, transfer_price, renew_price, created_at, updated_at) VALUES ('.com', 'local', 15.00, 15.00, 15.00, NOW(), NOW())",
        "INSERT INTO domain_pricing (tld, registrar_slug, register_price, transfer_price, renew_price, created_at, updated_at) VALUES ('.net', 'local', 12.00, 12.00, 12.00, NOW(), NOW())",
        "INSERT INTO domain_pricing (tld, registrar_slug, register_price, transfer_price, renew_price, created_at, updated_at) VALUES ('.org', 'local', 14.00, 14.00, 14.00, NOW(), NOW())",
        "INSERT INTO domain_pricing (tld, registrar_slug, register_price, transfer_price, renew_price, created_at, updated_at) VALUES ('.com.ng', 'local', 5.00, 5.00, 5.00, NOW(), NOW())",
        "INSERT INTO domain_pricing (tld, registrar_slug, register_price, transfer_price, renew_price, created_at, updated_at) VALUES ('.ng', 'local', 10.00, 10.00, 10.00, NOW(), NOW())",
    ],
];
