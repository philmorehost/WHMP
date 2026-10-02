<?php

declare(strict_types=1);

// WHERE A RESELLER'S MONEY GOES — the destination-recording step (payout plan
// §8 Phase D).
//
// Phase D's own note is precise about why gateway payouts were not built: it is
// not the gateway that is missing, it is the DESTINATION. `resellers` holds
// branding and a domain, `reseller_payouts` holds a method/reference/note, and
// nowhere in the schema is there an account number, a bank code or a recipient
// token. A manual bank transfer works only because the admin supplies the
// destination from outside the system — so no automatic rail can be built until
// somewhere records where the money should go. This table is that somewhere.
//
// ONE DESTINATION PER STORE, for now. A unique key on reseller_id means "the
// account we pay", which is what a payout actually needs; modelling a list of
// candidate destinations would add a choice with no second use case behind it
// yet, and the "one open request" rule already means a store is only ever being
// paid in one place at a time.
//
// THE SNAPSHOT IS THE POINT, NOT A CONVENIENCE. `reseller_payouts.destination_
// snapshot` freezes the destination as it read when the request was made. A
// payout record has to say where the money was actually sent, and a reseller who
// moves bank afterwards must not retroactively change what an older payout's
// record says. Same reasoning as `reseller_statements.our_identity`: a document
// about the past must not be restated by a later edit.
//
// VERIFICATION IS A SEPARATE, OPTIONAL STEP. `verified_at`/`verified_by` record
// that a human confirmed the details; nothing in the application can prove a
// bank account exists, so an unverified destination is stored and shown plainly
// rather than pretending to be checked.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $db->statement(
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS reseller_payout_destinations (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reseller_id INT UNSIGNED NOT NULL,
                    client_id INT UNSIGNED NULL,
                    method VARCHAR(32) NOT NULL DEFAULT 'bank_transfer',
                    account_name VARCHAR(191) NOT NULL,
                    account_number VARCHAR(64) NOT NULL,
                    bank_name VARCHAR(191) NULL,
                    bank_code VARCHAR(32) NULL,
                    currency_id INT UNSIGNED NULL COMMENT 'NULL = the base currency',
                    verified_at DATETIME NULL,
                    verified_by INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_reseller_payout_dest_store (reseller_id),
                    INDEX idx_reseller_payout_dest_client (client_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                SQL
            );

            // Constraints added separately so a re-run after a partial deploy
            // still gets them (same shape as 0191).
            $has = static function (\CodeVault\Database $db, string $constraint): bool {
                return $db->select(
                    'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    ['reseller_payout_destinations', $constraint]
                ) !== [];
            };

            if (!$has($db, 'fk_reseller_payout_dest_store')) {
                $db->statement(
                    'ALTER TABLE reseller_payout_destinations ADD CONSTRAINT fk_reseller_payout_dest_store
                     FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE'
                );
            }

            if (!$has($db, 'fk_reseller_payout_dest_client')) {
                $db->statement(
                    'ALTER TABLE reseller_payout_destinations ADD CONSTRAINT fk_reseller_payout_dest_client
                     FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL'
                );
            }

            if (!$has($db, 'fk_reseller_payout_dest_admin')) {
                $db->statement(
                    'ALTER TABLE reseller_payout_destinations ADD CONSTRAINT fk_reseller_payout_dest_admin
                     FOREIGN KEY (verified_by) REFERENCES admins(id) ON DELETE SET NULL'
                );
            }

            // The frozen copy of the destination at request time. Additive and
            // guarded, so re-running neither drops data nor fails on a column
            // that is already there.
            $columnExists = $db->select(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['reseller_payouts', 'destination_snapshot']
            ) !== [];

            if (!$columnExists) {
                $db->statement(
                    'ALTER TABLE reseller_payouts
                     ADD COLUMN destination_snapshot TEXT NULL
                     COMMENT "frozen destination text at request time; never restated"'
                );
            }
        },
    ],
];
