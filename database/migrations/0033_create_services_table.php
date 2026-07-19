<?php

declare(strict_types=1);

// The ongoing billable thing a client owns — separate from `orders`, which
// is just the one-time snapshot of what was purchased (blueprint R5 gap:
// recurring billing needs an entity with a `next_due_date` to bill
// against). Provisioning-module linkage (which server fulfills this)
// attaches here later in R6 via nullable columns, not a rewrite.
// `amount` is the recurring per-cycle price only — setup fees are one-time
// and already billed on the order's first invoice.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS services (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NULL,
            product_id INT UNSIGNED NOT NULL,
            product_name VARCHAR(191) NOT NULL,
            billing_cycle ENUM('one_time', 'monthly', 'quarterly', 'semi_annually', 'annually', 'biennially', 'triennially') NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status ENUM('pending', 'active', 'suspended', 'cancelled', 'terminated') NOT NULL DEFAULT 'pending',
            next_due_date DATE NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            INDEX idx_due (status, next_due_date),
            CONSTRAINT fk_services_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_services_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
            CONSTRAINT fk_services_product FOREIGN KEY (product_id) REFERENCES products(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
