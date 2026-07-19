<?php

declare(strict_types=1);

// Country(+state) tax rate rules (blueprint §4.4 tax/VAT engine).
// `state` NULL means the rule applies to the whole country; a
// country+state pair overrides the country-wide rule when both exist.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS tax_rules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            country_code CHAR(2) NOT NULL,
            state VARCHAR(100) NULL,
            name VARCHAR(100) NOT NULL DEFAULT 'Tax',
            rate DECIMAL(5,2) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_country_state (country_code, state)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
