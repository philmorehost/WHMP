<?php

declare(strict_types=1);

// The two things needed to reverse the COST side of a refund (plan §9 decision 4:
// "reverse both sides" — confirmed by the user 2026-10-01).
//
// 1. A LINK FROM A COST INVOICE LINE BACK TO THE ORDER IT CHARGED FOR
//
// 0193 built the receipt reversal and deliberately left the cost side, because
// reversing one order's share of an invoice needs the per-order figure the invoice
// was built from — and ResellerCostBillingJob::raise() was writing each line as an
// (invoice_id, description, amount) row with the order id only inside the
// DESCRIPTION TEXT. There was nothing to reverse against.
//
// The amount on the line is the one that matters, and not because it is convenient:
// `orders.cost_total` is denominated in the ORDER's currency and was converted to the
// reseller's currency AT BILLING TIME, using that day's rate. Recomputing the share
// today would convert at today's rate and credit back a different figure than we
// charged — a silent, permanent difference on a money path. The line is the record of
// what we actually billed, so the line is what the reversal reads.
//
// `order_id` is NULLABLE and ON DELETE SET NULL, matching every other invoice/order
// link in this schema: a document that was sent must outlive the order it names. A
// NULL also distinguishes the TAX line, which belongs to no order, from a line whose
// order has since been deleted — and a reversal should do nothing for either.
//
// 2. A `kind` FOR THE REVERSAL ITSELF
//
// The reversal is keyed on the COST invoice, and the original debit already holds
// `(cost_invoice, <that invoice>, 1)` under the forward key — so posting another
// `cost_invoice` against the same invoice would be refused as a duplicate. It needs
// its own kind.
//
// The ENUM is widened ADDITIVELY and the list carries `receipt_reversal` forward from
// 0193 (a MODIFY that omitted it would rewrite every existing reversal row to '' —
// this is the one place in this feature where getting the direction wrong destroys
// data). The alter is guarded on the current COLUMN_TYPE, so a re-run skips it. Note
// this MODIFY runs while `forward_flag` (0193) already depends on this column; if
// MariaDB refuses that, the failure is loud and immediate, not silent.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $columnType = static function (\CodeVault\Database $db, string $table, string $column): string {
                return (string) ($db->selectOne(
                    'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                )['COLUMN_TYPE'] ?? '');
            };

            $hasIndex = static function (\CodeVault\Database $db, string $table, string $index): bool {
                return $db->select(
                    'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$table, $index]
                ) !== [];
            };

            $hasConstraint = static function (\CodeVault\Database $db, string $table, string $constraint): bool {
                return $db->select(
                    'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    [$table, $constraint]
                ) !== [];
            };

            if ($columnType($db, 'invoice_items', 'order_id') === '') {
                $db->statement('ALTER TABLE invoice_items ADD COLUMN order_id INT UNSIGNED NULL AFTER amount');
            }

            if (!$hasIndex($db, 'invoice_items', 'idx_invoice_items_order')) {
                $db->statement('ALTER TABLE invoice_items ADD INDEX idx_invoice_items_order (order_id)');
            }

            if (!$hasConstraint($db, 'invoice_items', 'fk_invoice_items_order')) {
                $db->statement(
                    'ALTER TABLE invoice_items ADD CONSTRAINT fk_invoice_items_order
                     FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL'
                );
            }

            if (!str_contains($columnType($db, 'reseller_ledger', 'kind'), 'cost_reversal')) {
                $db->statement(
                    "ALTER TABLE reseller_ledger MODIFY COLUMN kind
                     ENUM('store_receipt','cost_invoice','payout','adjustment','receipt_reversal','cost_reversal') NOT NULL"
                );
            }
        },
    ],
];
