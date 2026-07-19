<?php

declare(strict_types=1);

// General key/value settings store (site title, install state, etc.).
// General Settings' many tabs (blueprint §4.3) populate this incrementally
// as each phase needs new keys — no upfront schema for settings we don't
// read yet.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(191) NOT NULL PRIMARY KEY,
            `value` TEXT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
