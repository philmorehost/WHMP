<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NULL,
            status ENUM('unpaid', 'paid', 'cancelled', 'refunded') NOT NULL DEFAULT 'unpaid',
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            due_date DATE NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            INDEX idx_client (client_id),
            INDEX idx_status (status),
            CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
