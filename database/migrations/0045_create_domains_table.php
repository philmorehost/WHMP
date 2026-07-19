<?php

declare(strict_types=1);

// The domain engine's core entity (blueprint §4.4). `registrar_slug`
// selects which RegistrarModule handles it — the same pattern
// `servers.module_slug`/`payment_gateways.slug` already use for their
// module types. `next_due_date` mirrors `services.next_due_date` so the
// same recurring-billing shape (generate ahead of due date, advance on
// renewal) applies to domain renewals too. `nameservers` is a cached copy
// for display — the registrar is always the source of truth, refreshed via
// RegistrarModule::getNameservers() on demand, not kept in sync automatically.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS domains (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NULL,
            domain_name VARCHAR(255) NOT NULL UNIQUE,
            tld VARCHAR(30) NOT NULL,
            registrar_slug VARCHAR(50) NOT NULL,
            registrar_domain_id VARCHAR(100) NULL,
            status ENUM('pending', 'active', 'expired', 'cancelled', 'transferred_away', 'grace', 'redemption') NOT NULL DEFAULT 'pending',
            registration_date DATE NULL,
            expiry_date DATE NULL,
            next_due_date DATE NULL,
            auto_renew TINYINT(1) NOT NULL DEFAULT 1,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            id_protection_enabled TINYINT(1) NOT NULL DEFAULT 0,
            registrar_lock_enabled TINYINT(1) NOT NULL DEFAULT 1,
            nameservers JSON NULL,
            provisioning_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client (client_id),
            INDEX idx_due (status, next_due_date),
            CONSTRAINT fk_domains_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_domains_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
