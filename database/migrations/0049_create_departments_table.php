<?php

declare(strict_types=1);

// Support departments (blueprint §4.3). `email` is the piped mailbox
// address for this department — used by the IMAP importer to route an
// inbound email to the right department.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS departments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            email VARCHAR(191) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
