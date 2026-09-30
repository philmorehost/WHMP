<?php

declare(strict_types=1);

// Numbered reseller statements: the FROZEN copy of a period's account.
//
// WHY THIS TABLE EXISTS AT ALL, GIVEN THE VIEW ALREADY WORKS
//
// The view at /admin/resellers/{clientId}/statement is recomputed from
// reseller_ledger on every read, which makes it always current and always
// correctable. That is the right property for a page and the WRONG property for a
// document: if two people open "statement 12" a week apart, one of them may have
// a late-arriving entry in it and the other may not, and neither can tell. A
// numbered document has to be able to answer "what did we tell them, and when" —
// so this table stores what was issued, and the document renders from HERE, never
// from the live ledger.
//
// A statement that could change after it was issued is not a statement, it is a
// screenshot.
//
// WHAT IS FROZEN, AND WHY EACH PIECE IS FROZEN
//
//   the five money columns   the figures as issued. Recomputing them later would
//                            let a back-dated entry rewrite a document the
//                            reseller has already filed.
//   line_items               the itemised entries, as JSON. Stored rather than
//                            re-read so the document is self-contained: it must
//                            survive an entry being corrected or deleted.
//   our_identity             our tax identity AS IT WAS THEN. A company that
//                            changes its VAT number must not have that change
//                            retroactively alter documents it already issued —
//                            that is the whole reason a tax document is stamped
//                            with an identity at all.
//   currency_rate            the rate used for the converted closing totals,
//                            LOCKED at issue. Deriving it from today's rate would
//                            make a reprint disagree with the original.
//
// NOTE WHAT IS *NOT* STORED: no balance column, and no running total per line.
// The ledger remains the only place a balance lives. These columns are a
// historical RECORD of one reading of it, which is a different thing from a
// second source of truth — the distinction is that nothing reads them to answer
// "what is owed", only "what did we say was owed on this date".
//
// THREE UNIQUE KEYS, EACH DOING A DIFFERENT JOB
//
//   (reseller_id, period_year, seq)   the allocator. This is what makes the
//                                     number sequence per-store-per-year and
//                                     makes a duplicate number impossible even if
//                                     two requests race.
//   (reseller_id, number)             belt and braces: catches a bug in the
//                                     number FORMATTING, which the seq key alone
//                                     would not, because two different seqs could
//                                     in principle format to the same string.
//   (reseller_id, period_from, period_to)
//                                     IDEMPOTENCY. Issuing a statement for a
//                                     period that has already been issued returns
//                                     the existing row instead of minting a second
//                                     number for the same thing. Same principle as
//                                     orders.reseller_cost_invoice_id: the guard
//                                     is a property of the data, not of the run.
//
// A NUMBER IS NEVER REUSED, and the sequence is deliberately NOT gapless. A
// gapless sequence would require reusing a number when a transaction rolls back,
// and a number that can be handed out twice is exactly what makes a numbered
// document useless in an audit. A gap means something real happened.
//
// ON DELETE: deleting a store takes its statements with it (CASCADE, matching
// reseller_ledger and reseller_payouts). Deleting the client or the issuing admin
// only un-links the row — a document that went out must outlive the accounts it
// names.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $db->statement(
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS reseller_statements (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reseller_id INT UNSIGNED NOT NULL,
                    client_id INT UNSIGNED NULL,
                    number VARCHAR(32) NOT NULL COMMENT 'human-facing, e.g. STMT-2026-0001; never reused',
                    seq INT UNSIGNED NOT NULL COMMENT 'per store, per year',
                    period_year SMALLINT UNSIGNED NOT NULL COMMENT 'the year the number is scoped to (the period end)',
                    period_from DATETIME NOT NULL,
                    period_to DATETIME NOT NULL,
                    opening_base DECIMAL(18,6) NOT NULL COMMENT 'BASE currency, as issued',
                    credits_base DECIMAL(18,6) NOT NULL,
                    debits_base DECIMAL(18,6) NOT NULL,
                    closing_base DECIMAL(18,6) NOT NULL,
                    withdrawable_base DECIMAL(18,6) NOT NULL COMMENT 'as at the period end',
                    entry_count INT UNSIGNED NOT NULL DEFAULT 0,
                    currency_id INT UNSIGNED NULL COMMENT 'the reseller currency used for the conversion',
                    currency_code VARCHAR(8) NULL,
                    currency_rate DECIMAL(18,6) NOT NULL DEFAULT 1.000000 COMMENT 'rate LOCKED at issue; never recomputed',
                    closing_converted DECIMAL(18,6) NOT NULL DEFAULT 0,
                    withdrawable_converted DECIMAL(18,6) NOT NULL DEFAULT 0,
                    line_items MEDIUMTEXT NOT NULL COMMENT 'frozen JSON snapshot of the entries (NOT `lines`: a reserved word in MariaDB)',
                    our_identity MEDIUMTEXT NOT NULL COMMENT 'frozen JSON snapshot of our tax identity at issue',
                    issued_at DATETIME NOT NULL,
                    issued_by INT UNSIGNED NULL,
                    UNIQUE KEY uq_reseller_statements_seq (reseller_id, period_year, seq),
                    UNIQUE KEY uq_reseller_statements_number (reseller_id, number),
                    UNIQUE KEY uq_reseller_statements_period (reseller_id, period_from, period_to),
                    INDEX idx_reseller_statements_store (reseller_id, issued_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                SQL
            );

            // Added separately from the CREATE, so an install that already has the
            // table (a re-run after a partial deploy) still gets its constraints
            // rather than silently keeping a table without them.
            $has = static function (\CodeVault\Database $db, string $constraint): bool {
                return $db->select(
                    'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    ['reseller_statements', $constraint]
                ) !== [];
            };

            if (!$has($db, 'fk_reseller_statements_store')) {
                $db->statement(
                    'ALTER TABLE reseller_statements ADD CONSTRAINT fk_reseller_statements_store
                     FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE'
                );
            }

            if (!$has($db, 'fk_reseller_statements_client')) {
                $db->statement(
                    'ALTER TABLE reseller_statements ADD CONSTRAINT fk_reseller_statements_client
                     FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL'
                );
            }

            if (!$has($db, 'fk_reseller_statements_admin')) {
                $db->statement(
                    'ALTER TABLE reseller_statements ADD CONSTRAINT fk_reseller_statements_admin
                     FOREIGN KEY (issued_by) REFERENCES admins(id) ON DELETE SET NULL'
                );
            }
        },

        // Our own tax identity, for the document header. Seeded EMPTY rather than
        // with placeholder text: a tax document that prints a made-up VAT number
        // is worse than one that prints nothing, because the placeholder would be
        // filled in only by accident. The statement page says which fields are
        // missing instead, so an admin can see what has to be set. `company.name`
        // is NOT re-seeded — it already exists and is the legal name.
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('company.tax_number', '')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('company.registration_number', '')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('company.address', '')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('company.email', '')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('company.phone', '')",
    ],
];
