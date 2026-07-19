<?php

declare(strict_types=1);

// `fraud` status exists in the enum so the FraudModule contract (§3) and
// the Pending Orders queue (§4.3) have somewhere to land a hold decision —
// actual fraud scoring is a later phase (R9); orders just carry the status today.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            status ENUM('pending', 'active', 'cancelled', 'fraud') NOT NULL DEFAULT 'pending',
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            INDEX idx_status (status),
            CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
