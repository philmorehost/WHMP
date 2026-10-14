<?php

declare(strict_types=1);

// Sub-resellers: a customer of a reseller's store who opens a store of their own.
//
// THE MONEY RULE
//
// A sub-reseller buys at the prices of the reseller they registered under (their
// "upline"), never at the platform's reseller discount — otherwise the upline would
// lose its profit on every customer who turned reseller. So on a sub-reseller's
// order there are three figures, all in the order's currency:
//
//   total              — what the customer paid (the sub-reseller's retail)
//   cost_total         — what the sub-reseller owes: the UPLINE's retail
//   upline_cost_total  — what the upline owes us: list less the platform discount
//
// The sub-reseller keeps total − cost_total (store_receipt − cost_invoice, as for any
// store). The upline earns cost_total − upline_cost_total, posted to the upline's own
// account as `upline_margin` when the customer's invoice is paid, and reversed
// (`upline_margin_reversal`) on a refund. The platform keeps upline_cost_total.
//
// orders.upline_reseller_id is a SNAPSHOT taken at checkout, like orders.reseller_id:
// moving the sub-reseller to another provider later does not re-route money already
// earned. ON DELETE SET NULL — a deleted upline store takes its own ledger with it.
//
// The ledger ENUM is widened ADDITIVELY, carrying every existing member forward (a
// MODIFY that dropped one would rewrite those rows to ''). Neither new kind is a
// "forward" kind for the generated forward_flag key (0193): idempotency of the margin
// comes from the store_receipt it is posted alongside, which IS unique per invoice.

return [
    'up' => [
        static function (\CodeVault\Database $db): void {
            $column = static fn (string $table, string $name): string => (string) ($db->selectOne(
                'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $name]
            )['COLUMN_TYPE'] ?? '');

            if ($column('orders', 'upline_reseller_id') === '') {
                $db->statement('ALTER TABLE orders ADD COLUMN upline_reseller_id INT UNSIGNED NULL AFTER cost_total');
            }

            if ($column('orders', 'upline_cost_total') === '') {
                $db->statement('ALTER TABLE orders ADD COLUMN upline_cost_total DECIMAL(10,2) NULL AFTER upline_reseller_id');
            }

            $hasFk = $db->selectOne(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'fk_orders_upline_reseller'"
            );

            if ($hasFk === null) {
                $db->statement(
                    'ALTER TABLE orders ADD CONSTRAINT fk_orders_upline_reseller
                     FOREIGN KEY (upline_reseller_id) REFERENCES resellers(id) ON DELETE SET NULL'
                );
            }

            if (!str_contains($column('reseller_ledger', 'kind'), 'upline_margin')) {
                $db->statement(
                    "ALTER TABLE reseller_ledger MODIFY COLUMN kind
                     ENUM('store_receipt','cost_invoice','payout','adjustment','receipt_reversal','cost_reversal','upline_margin','upline_margin_reversal') NOT NULL"
                );
            }
        },
    ],
];
