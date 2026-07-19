<?php

declare(strict_types=1);

// Server groups (blueprint §4.4 "server groups + load balancing") — a
// product provisions onto a group, not a single server, so capacity can
// be added by dropping another server into the group.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS server_groups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
