<?php

declare(strict_types=1);

// Editable email templates (blueprint §5 "Async email"). `key` is the
// stable code-level identifier an engine fires by (e.g. 'client_welcome');
// subject/body are what admins edit.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS email_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(191) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_html TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
