<?php

declare(strict_types=1);

// Reversing a store receipt when its sale is refunded (plan §9 decision 4:
// "reverse both sides, as appended entries").
//
// WHY THIS IS A SEPARATE MIGRATION FROM 0190
//
// 0190 created the ledger with `kind ENUM('store_receipt','cost_invoice','payout',
// 'adjustment')` and `UNIQUE (kind, invoice_id)`. Both of those are right for the
// FORWARD postings and wrong for a reversal:
//
//   the ENUM    has no reversal kind, so a reversal cannot be recorded at all.
//   the UNIQUE  permits exactly ONE row per (kind, invoice_id). That is the
//               guarantee that makes double-crediting impossible, and it must
//               stay — but it would also cap reversals at one, so a second
//               partial refund on the same invoice could never be posted.
//
// THE UNIQUE KEY IS REPLACED, NOT DROPPED
//
// Dropping it would hand back the one thing it exists to prevent. Instead it moves
// to (kind, invoice_id, forward_flag), where forward_flag is a generated column
// that is 1 for the forward kinds and NULL for everything else:
//
//   forward postings  (store_receipt, cost_invoice) -> flag 1 -> still exactly one
//                      per invoice, exactly as before.
//   reversals and the rest -> flag NULL -> unlimited, because MySQL treats the
//                      NULLs in a unique key as distinct. This is the same trick
//                      reseller_payouts uses for "at most one open request".
//
// So the guarantee that existed before is preserved verbatim for the rows it was
// written for, and only the reversal case is un-capped.
//
// WIDENING THE ENUM IS ADDITIVE, AND THAT MATTERS
//
// `MODIFY COLUMN kind ENUM(<longer list>)` REWRITES every row whose value is not a
// member of the new list to ''. Widening keeps every existing value a member, so
// nothing is lost — but the direction is load-bearing, and a migration that later
// NARROWED this list would silently wipe data. The ALTER is also guarded on the
// current COLUMN_TYPE, so a re-run (a partial deploy) is skipped rather than
// re-executed against a generated column that already depends on this column.
//
// ONLY `receipt_reversal` IS ADDED. The cost side of the same decision is not built
// yet, and adding a kind nothing can write yet would be untested state pretending
// to be progress. It is one additive ALTER when that half lands.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $columnType = static function (\CodeVault\Database $db, string $column): string {
                return (string) ($db->selectOne(
                    'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    ['reseller_ledger', $column]
                )['COLUMN_TYPE'] ?? '');
            };

            $hasIndex = static function (\CodeVault\Database $db, string $index): bool {
                return $db->select(
                    'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    ['reseller_ledger', $index]
                ) !== [];
            };

            // 1. Widen the enum, but only if it does not already include the new
            //    member. Must happen BEFORE the generated column is added, because
            //    the generated column depends on this column.
            if (!str_contains($columnType($db, 'kind'), 'receipt_reversal')) {
                $db->statement(
                    "ALTER TABLE reseller_ledger MODIFY COLUMN kind
                     ENUM('store_receipt','cost_invoice','payout','adjustment','receipt_reversal') NOT NULL"
                );
            }

            // 2. The discriminator. Persisted rather than virtual so the unique key
            //    can be enforced by the storage engine.
            if ($columnType($db, 'forward_flag') === '') {
                $db->statement(
                    "ALTER TABLE reseller_ledger ADD COLUMN forward_flag TINYINT
                     AS (IF(kind IN ('store_receipt','cost_invoice'), 1, NULL)) PERSISTENT"
                );
            }

            // 3. Swap the key. Drop first, or the two would overlap and the forward
            //    guarantee would still be the narrower one.
            if ($hasIndex($db, 'uq_reseller_ledger_invoice_kind')) {
                $db->statement('ALTER TABLE reseller_ledger DROP INDEX uq_reseller_ledger_invoice_kind');
            }

            if (!$hasIndex($db, 'uq_reseller_ledger_forward')) {
                $db->statement(
                    'ALTER TABLE reseller_ledger
                     ADD UNIQUE KEY uq_reseller_ledger_forward (kind, invoice_id, forward_flag)'
                );
            }
        },
    ],
];
