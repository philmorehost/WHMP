<?php

declare(strict_types=1);

// Single-row activation record for the ported DGV7.11 licensing pattern
// (blueprint §5/§6) — deliberately small: this install is internal-only,
// so there's no multi-license/domain-count schema here, just self-integrity
// state for one domain.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS license_activation (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain VARCHAR(191) NOT NULL,
            status ENUM('pending', 'active', 'grace', 'suspended') NOT NULL DEFAULT 'pending',
            last_checked_at DATETIME NULL,
            last_valid_at DATETIME NULL,
            cached_response TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
