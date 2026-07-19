<?php

declare(strict_types=1);

// Sub-accounts with granular permissions (blueprint §4.1 "Contacts/Sub-accounts").
// `permissions` is a JSON array of permission keys scoped to what a
// sub-account contact is allowed to see/do on the parent client's account.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_contacts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            email VARCHAR(191) NOT NULL,
            permissions JSON NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            CONSTRAINT fk_client_contacts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
