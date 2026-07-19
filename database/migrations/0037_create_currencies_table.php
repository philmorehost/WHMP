<?php

declare(strict_types=1);

// Multi-currency stub (blueprint §4.4/§4.5): the schema and a seeded
// default currency exist, but invoices are not yet generated or displayed
// in anything but the default currency — real conversion at order/invoice
// time is deferred (flagged, not silently half-built).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS currencies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code CHAR(3) NOT NULL UNIQUE,
            symbol VARCHAR(5) NOT NULL,
            exchange_rate DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        "INSERT INTO currencies (code, symbol, exchange_rate, is_default, created_at, updated_at) VALUES ('USD', '$', 1.0000, 1, NOW(), NOW())",
    ],
];
