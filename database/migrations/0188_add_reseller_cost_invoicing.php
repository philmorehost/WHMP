<?php

declare(strict_types=1);

// Billing the reseller for the cost of the orders their store took.
//
// Phase 3 wrote orders.cost_total (catalogue less the admin's reseller
// discount) on every order a store took. Nothing billed it. This migration adds
// the one column that makes billing it correct.
//
//   orders.reseller_cost_invoice_id — the cost invoice that billed this order.
//                                     NULL means "not billed yet", and that is
//                                     the only state worth tracking:
//                                     idempotency becomes a property of the
//                                     data rather than a marker on the run, so
//                                     a cron that runs late, runs twice, or
//                                     misses a month cannot double-bill or skip.
//
//                                     It is also the audit link — "which invoice
//                                     charged me for order 812" is one join.
//
// ON DELETE SET NULL: deleting an invoice un-bills its orders rather than
// deleting them. The money records outlive the document.
//
// The three tunables live in `settings` like every other admin knob, and their
// defaults are chosen so this migration changes no behaviour until an admin
// asks for something else:
//
//   reseller.billing_auto      '1'     bill monthly without being asked
//   reseller.billing_due_days  '7'     payment terms on the cost invoice
//   reseller.billing_minimum   '0.00'  invoice any amount; an accrual below a
//                                      raised minimum is carried forward into
//                                      the next invoice rather than dropped
//
// Guarded through INFORMATION_SCHEMA (see 0179/0182/0183/0184) so this is
// portable and safe to re-apply under the automatic on-boot migrator.

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

            $add($db, 'orders', 'reseller_cost_invoice_id', 'INT UNSIGNED NULL AFTER cost_total');

            // The billing sweep asks "unbilled cost for this store?", which is
            // exactly this pair, and the report asks it per store too.
            $indexes = $db->select(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_reseller_cost'"
            );

            if ($indexes === []) {
                $db->statement('ALTER TABLE orders ADD INDEX idx_reseller_cost (reseller_id, reseller_cost_invoice_id)');
            }

            $fk = $db->select(
                "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
                   AND CONSTRAINT_NAME = 'fk_orders_reseller_cost_invoice'"
            );

            if ($fk === []) {
                $db->statement(
                    'ALTER TABLE orders ADD CONSTRAINT fk_orders_reseller_cost_invoice
                     FOREIGN KEY (reseller_cost_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL'
                );
            }
        },
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('reseller.billing_auto', '1')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('reseller.billing_due_days', '7')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('reseller.billing_minimum', '0.00')",
    ],
];
