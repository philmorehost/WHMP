<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS domain_dns_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            type VARCHAR(10) NOT NULL DEFAULT 'A',
            name VARCHAR(255) NOT NULL DEFAULT '@',
            content TEXT NOT NULL,
            priority INT NULL DEFAULT 10,
            ttl INT NOT NULL DEFAULT 3600,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_domain_id (domain_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL,

        <<<'SQL'
        CREATE TABLE IF NOT EXISTS domain_child_nameservers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            hostname VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_child_ns_domain_id (domain_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL,
    ],
];
