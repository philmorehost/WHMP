<?php

declare(strict_types=1);

// Attribution and cost for orders placed at a reseller's store.
//
// Three columns, each answering a different question:
//
//   orders.reseller_id   — WHICH store took the order. NULL is the platform's
//                          own checkout and every order that predates stores,
//                          so existing rows keep their meaning.
//   orders.cost_total    — what the reseller owes us for it: the catalogue
//                          price less the admin's reseller discount. This is
//                          OUR revenue from the sale; orders.total (retail) is
//                          what the customer paid.
//   order_items.cost_price — the same figure per line, snapshotted like
//                          unit_price so a later price change cannot rewrite
//                          what an order was worth or what it cost.
//
// clients.reseller_id records which store brought an account in. It is only
// ever set on an account that has no owner yet — a reseller cannot capture an
// account we already have (see CheckoutService::attributeClientToStore()).
//
// All four are nullable with no default, because NULL is a real answer here:
// "this did not happen through a store".

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $add = static function (\CodeVault\Database $db, string $table, string $column, string $definition): void {
                $exists = $db->select(
                    'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                );

                if ($exists === []) {
                    $db->statement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            };

            $add($db, 'orders', 'reseller_id', 'INT UNSIGNED NULL AFTER client_id');
            $add($db, 'orders', 'cost_total', 'DECIMAL(10,2) NULL AFTER total');
            $add($db, 'order_items', 'cost_price', 'DECIMAL(10,2) NULL AFTER unit_price');
            $add($db, 'clients', 'reseller_id', 'INT UNSIGNED NULL AFTER client_group_id');

            $indexes = $db->select(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_reseller'"
            );

            if ($indexes === []) {
                $db->statement('ALTER TABLE orders ADD INDEX idx_reseller (reseller_id)');
            }

            // ON DELETE SET NULL, not CASCADE: deleting a store must never
            // delete the orders it took or the accounts it brought in — the
            // money and the customers outlive the storefront.
            $fk = $db->select(
                "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'fk_orders_reseller'"
            );

            if ($fk === []) {
                $db->statement('ALTER TABLE orders ADD CONSTRAINT fk_orders_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL');
            }

            $fkClients = $db->select(
                "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND CONSTRAINT_NAME = 'fk_clients_reseller'"
            );

            if ($fkClients === []) {
                $db->statement('ALTER TABLE clients ADD CONSTRAINT fk_clients_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL');
            }
        },
    ],
];
