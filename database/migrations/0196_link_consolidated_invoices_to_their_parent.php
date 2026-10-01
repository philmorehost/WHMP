<?php

declare(strict_types=1);

// Links the invoices a "Pay Selected Invoices" consolidation absorbed back to it.
//
// THE BUG THIS EXISTS TO FIX
//
// ClientInvoiceController::massPay() builds ONE consolidated invoice whose lines
// are each source invoice's total (that part is correct, and tested), and then
// stops. Nothing recorded WHICH invoices it absorbed, and nothing settled them. So
// paying the consolidation left every source invoice `unpaid`:
//
//   - the client was still shown debts they had already settled;
//   - they kept counting in countOverdue() / sumOverdue(), so the dashboard's
//     overdue figures stayed inflated;
//   - the dunning sweep chased them for money already collected.
//
// In other words paying once left the customer owing it twice. The consolidation
// had the amounts but not the identity of what it covered, and without the identity
// no payment path can cascade.
//
// WHY THE COLUMN IS ON THE SOURCE INVOICE, NOT THE CONSOLIDATION
//
// A source is absorbed at most once — the picker only offers `unpaid` invoices, and
// once absorbed a source is no longer independently payable. So the relation is
// many-sources-to-one-consolidation, and storing it on the source makes "is this
// invoice consolidated, and into what?" answerable from a single row. That is the
// question every payable-facing query has to answer: overdue(), dueUnpaid(),
// unpaidIds(), countOverdue(), sumOverdue() and the client's own invoice list.
//
// A join table would answer the same question only by adding a subquery to each of
// them, and a column on the consolidation would make it unanswerable without a scan.
//
// NULLABLE + ON DELETE SET NULL, matching every other document link in this schema:
// a consolidation that is deleted must not delete the invoices it covered — the
// documents were sent, and the debts are real. NULL means "this invoice stands on
// its own", which is exactly what clearing the link on cancellation restores.
//
// The index is not decoration: the reverse lookup (children of a consolidation) runs
// on every payment cascade, and the null-test runs in the payable queries above.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $hasColumn = static function (\CodeVault\Database $db, string $table, string $column): bool {
                return (string) ($db->selectOne(
                    'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                )['COLUMN_NAME'] ?? '') !== '';
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

            if (!$hasColumn($db, 'invoices', 'parent_invoice_id')) {
                $db->statement('ALTER TABLE invoices ADD COLUMN parent_invoice_id INT UNSIGNED NULL');
            }

            if (!$hasIndex($db, 'invoices', 'idx_invoices_parent')) {
                $db->statement('ALTER TABLE invoices ADD INDEX idx_invoices_parent (parent_invoice_id)');
            }

            if (!$hasConstraint($db, 'invoices', 'fk_invoices_parent')) {
                $db->statement(
                    'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_parent
                     FOREIGN KEY (parent_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL'
                );
            }
        },
    ],
];
