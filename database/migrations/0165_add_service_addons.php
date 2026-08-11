<?php

declare(strict_types=1);

use CodeVault\Database;

// Recurring service add-ons (blueprint gap: no product/service add-ons —
// the biggest revenue lever, since a client can only ever own exactly the
// product they ordered, never an extra billable line on top).
//
// Design: an add-on is just a CHILD services row linked to a parent
// service via services.parent_id. That is the whole trick — it means every
// existing engine that already sweeps/acts on the services table (the
// recurring billing job, dunning, suspension, cancellation, the client
// services list) works for add-ons with zero changes: an add-on bills on
// its own next_due_date/amount like any other service.
//
// product_addons is the admin-facing config: which products may be sold as
// add-ons to which parent products, optionally pinned to a billing cycle
// (NULL = follow the parent service's cycle). The add-on's own
// product_pricing rows supply the price for the chosen cycle.

return [
    'up' => [
        static function (Database $db): void {
            // Self-referencing FK: an add-on is a service whose parent_id
            // points at the service it's attached to. ALTER (not part of the
            // original 0033 CREATE) so the migration is idempotent-ish and
            // independent of how the table was originally built.
            $db->statement(
                <<<'SQL'
                ALTER TABLE services
                    ADD COLUMN parent_id INT UNSIGNED NULL AFTER order_id,
                    ADD KEY idx_services_parent (parent_id),
                    ADD CONSTRAINT fk_services_parent FOREIGN KEY (parent_id) REFERENCES services(id) ON DELETE CASCADE
                SQL
            );

            $db->statement(
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS product_addons (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    parent_product_id INT UNSIGNED NOT NULL,
                    addon_product_id INT UNSIGNED NOT NULL,
                    billing_cycle ENUM('one_time', 'monthly', 'quarterly', 'semi_annually', 'annually', 'biennially', 'triennially') NULL,
                    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                    status ENUM('active', 'hidden') NOT NULL DEFAULT 'active',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_product_addon (parent_product_id, addon_product_id, billing_cycle),
                    CONSTRAINT fk_addons_parent_product FOREIGN KEY (parent_product_id) REFERENCES products(id) ON DELETE CASCADE,
                    CONSTRAINT fk_addons_addon_product FOREIGN KEY (addon_product_id) REFERENCES products(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL
            );
        },
    ],
];
