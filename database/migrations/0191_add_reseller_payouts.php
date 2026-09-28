<?php

declare(strict_types=1);

// Payout requests: a reseller asking for money, and an admin answering.
//
// WHAT A ROW HERE IS, AND WHAT IT IS NOT
//
// The account itself lives in reseller_ledger and stays the single source of
// truth for what is owed. This table holds the DECISION and the PAPERWORK — the
// amount in the reseller's currency, the rate that was locked, the bank reference
// the admin typed. It deliberately holds no balance: a balance that is both
// stored and derived will eventually disagree with itself, and the whole point of
// the ledger is that one sum answers the question.
//
// amount_base IS WHAT MOVED, AND amount IS WHAT WAS SENT
//
// amounts are stored twice on purpose, and they are not redundant:
//
//   amount_base   the base-currency figure that left the account. This is the
//                 number the ledger is debited by, so the ledger and the payout
//                 can be reconciled by arithmetic rather than by trust.
//   amount        the figure actually paid out in the reseller's currency, at
//                 the rate LOCKED at the moment of the request and recorded
//                 alongside it. This is what the bank statement will show, and
//                 re-deriving it later from today's rate would make a settled
//                 payout disagree with the transfer that settled it.
//
// The locked rate is the whole FX decision made concrete: the reseller carries
// the movement, so the rate is fixed when the payout is requested and never
// recomputed.
//
// ONE OPEN REQUEST PER RESELLER, ENFORCED BY THE DATABASE
//
// Not by a service check, and not by a status unique key — UNIQUE (reseller_id,
// status) would permit exactly one PAID payout per reseller ever, which is the
// opposite of the intent. A generated column that is 1 only while pending, with a
// unique key on (reseller_id, open_flag), expresses "at most one open request at a
// time" as something the storage engine refuses. MySQL/MariaDB permit repeated
// NULLs in a unique key, so decided rows — which produce NULL — never collide, and
// any number of them may exist.
//
// This matters because the alternative is arithmetic that can double-spend: two
// pending requests, each for the full balance, approved in sequence.
//
// The ledger is also debited when the request is MADE, not when it is paid, so
// even a bypass of the rule above cannot spend the same money twice: the first
// request has already reduced the withdrawable figure the second one would have
// been checked against.
//
// ON DELETE: deleting a store takes its payout history with it (CASCADE, matching
// reseller_ledger). Deleting the client or the admin who decided only un-links the
// row — a payout record is a money record and must outlive the accounts it names.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $db->statement(
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS reseller_payouts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reseller_id INT UNSIGNED NOT NULL,
                    client_id INT UNSIGNED NULL,
                    amount DECIMAL(18,6) NOT NULL COMMENT 'reseller currency, exactly as sent',
                    currency_id INT UNSIGNED NULL COMMENT 'NULL = the base currency',
                    currency_rate DECIMAL(18,6) NOT NULL COMMENT 'rate LOCKED at request; never recomputed',
                    amount_base DECIMAL(18,6) NOT NULL COMMENT 'base currency figure the account was debited by',
                    status ENUM('pending','paid','rejected','cancelled') NOT NULL DEFAULT 'pending',
                    method VARCHAR(32) NOT NULL DEFAULT 'bank_transfer',
                    reference VARCHAR(191) NULL COMMENT 'the bank/transfer reference the admin recorded',
                    note TEXT NULL COMMENT 'why it was rejected, or anything the admin needs to record',
                    requested_at DATETIME NOT NULL,
                    decided_at DATETIME NULL,
                    decided_by INT UNSIGNED NULL,
                    open_flag TINYINT AS (IF(status = 'pending', 1, NULL)) PERSISTENT,
                    UNIQUE KEY uq_reseller_payouts_open (reseller_id, open_flag),
                    INDEX idx_reseller_payouts_queue (status, requested_at),
                    INDEX idx_reseller_payouts_account (reseller_id, requested_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                SQL
            );

            // Added separately from the CREATE so an install that already has the
            // table (a re-run after a partial deploy) still gets its constraints
            // rather than silently keeping a table without them.
            $has = static function (\CodeVault\Database $db, string $constraint): bool {
                return $db->select(
                    'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    ['reseller_payouts', $constraint]
                ) !== [];
            };

            if (!$has($db, 'fk_reseller_payouts_store')) {
                $db->statement(
                    'ALTER TABLE reseller_payouts ADD CONSTRAINT fk_reseller_payouts_store
                     FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE'
                );
            }

            if (!$has($db, 'fk_reseller_payouts_client')) {
                $db->statement(
                    'ALTER TABLE reseller_payouts ADD CONSTRAINT fk_reseller_payouts_client
                     FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL'
                );
            }

            if (!$has($db, 'fk_reseller_payouts_admin')) {
                $db->statement(
                    'ALTER TABLE reseller_payouts ADD CONSTRAINT fk_reseller_payouts_admin
                     FOREIGN KEY (decided_by) REFERENCES admins(id) ON DELETE SET NULL'
                );
            }
        },
    ],
];
