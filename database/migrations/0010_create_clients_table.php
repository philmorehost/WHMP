<?php

declare(strict_types=1);

// Core client record (blueprint §4.3 Clients / §6 data model). Services,
// domains, invoices, and tickets attach to this once their engines land
// (R4-R9) — this migration is just the client identity + contact record.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS clients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_group_id INT UNSIGNED NULL,
            email VARCHAR(191) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            company_name VARCHAR(191) NULL,
            address1 VARCHAR(191) NULL,
            address2 VARCHAR(191) NULL,
            city VARCHAR(100) NULL,
            state VARCHAR(100) NULL,
            postcode VARCHAR(20) NULL,
            country CHAR(2) NULL,
            phone VARCHAR(40) NULL,
            status ENUM('active', 'inactive', 'closed') NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_status (status),
            CONSTRAINT fk_clients_group FOREIGN KEY (client_group_id) REFERENCES client_groups(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
