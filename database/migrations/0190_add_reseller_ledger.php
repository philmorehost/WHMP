<?php

declare(strict_types=1);

// The reseller's running account: what we owe a store, and why.
//
// WHY THIS TABLE IS NOT IN THE RESELLER'S CURRENCY
//
// Phase 4 bills the store in the reseller's own account currency, and it is
// tempting to run this account in that currency too. The user's FX decision
// forbids it: the RESELLER carries the exchange movement between the day we
// collect a customer's payment and the day we pay the reseller out. That is only
// expressible if the account is kept in ONE stable unit and converted at the
// edges, so:
//
//   amount is always the BASE-currency figure, frozen at the moment the entry
//   is written. The reseller's own currency figure is a CONVERSION of that, done
//   on read and again at payout, which is precisely why it moves.
//
// Storing a per-entry currency_id would make the account unsummable — the
// mistake client_credit_ledger already made (client-currency grants mixed with
// base-currency credit-note totals, so no single factor ever fitted it, and it
// is therefore off-limits for reuse here; see the payout plan §3).
//
// WHY (kind, invoice_id) IS UNIQUE, AND WHY (kind, order_id) DELIBERATELY IS NOT
//
// Idempotency is a property of the DATA, not of the run — the same reason
// orders.reseller_cost_invoice_id is the marker for cost billing. An INVOICE_PAID
// hook can fire more than once for one invoice (a manual settle after an
// automatic one, a retried callback), and the unique key makes the second post
// impossible rather than merely unlikely. Two different kinds for one invoice
// (a receipt, or the cost that invoice billed) are distinct rows and both
// allowed, which is exactly the netting the account exists to express.
//
// The invoice is the right unit for the RECEIPT too, even though one order has one
// invoice: an order can be credited once and REVERSED once, and a second reversal
// is legitimate (partial refunds), so keying on the order would cap reversals at
// one and silently refuse the rest.
//
// MySQL permits repeated NULLs in a unique key, so reversals, adjustments and
// payouts — none of which carry a document id — are unaffected, and a reversal is
// still free to point at the order it reverses for audit.
//
// WHY withdrawable_at IS A COLUMN AND NOT A RULE
//
// The agreed holding period is 30 days: a receipt becomes withdrawable 30 days
// after the payment that funded it, covering the card chargeback window. Because
// the date is written when the row is written, adding or changing the period
// later cannot retroactively fix history — so it must exist from the first row.
// NULL means "not subject to a holding period at all", which is what every debit
// wants: a cost or a payout reduces the balance immediately.
//
// ON DELETE for the FKs: deleting a store takes its ledger with it (CASCADE,
// because the account is meaningless without the store), but deleting an order or
// an invoice only un-links the entry (SET NULL) — the money record outlives the
// document that caused it, and a balance must not change because a document was
// tidied away.

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $db->statement(
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS reseller_ledger (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reseller_id INT UNSIGNED NOT NULL,
                    client_id INT UNSIGNED NULL,
                    kind ENUM('store_receipt','cost_invoice','payout','adjustment') NOT NULL,
                    amount DECIMAL(18,6) NOT NULL COMMENT 'BASE currency, signed: + owed to the reseller, - owed by them',
                    withdrawable_at DATETIME NULL COMMENT 'NULL = immediate; receipts carry payment date + holding period',
                    order_id INT UNSIGNED NULL,
                    invoice_id INT UNSIGNED NULL,
                    payout_id INT UNSIGNED NULL,
                    description VARCHAR(255) NULL,
                    admin_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL,
                    UNIQUE KEY uq_reseller_ledger_invoice_kind (kind, invoice_id),
                    INDEX idx_reseller_ledger_account (reseller_id, created_at),
                    INDEX idx_reseller_ledger_order (order_id),
                    INDEX idx_reseller_ledger_withdrawable (reseller_id, withdrawable_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                SQL
            );

            // Added separately from the CREATE so an install that already has the
            // table (a re-run after a partial deploy) still gets its constraints
            // instead of silently keeping a table without them.
            $has = static function (\CodeVault\Database $db, string $constraint): bool {
                return $db->select(
                    'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    ['reseller_ledger', $constraint]
                ) !== [];
            };

            if (!$has($db, 'fk_reseller_ledger_store')) {
                $db->statement(
                    'ALTER TABLE reseller_ledger ADD CONSTRAINT fk_reseller_ledger_store
                     FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE'
                );
            }

            if (!$has($db, 'fk_reseller_ledger_order')) {
                $db->statement(
                    'ALTER TABLE reseller_ledger ADD CONSTRAINT fk_reseller_ledger_order
                     FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL'
                );
            }

            if (!$has($db, 'fk_reseller_ledger_invoice')) {
                $db->statement(
                    'ALTER TABLE reseller_ledger ADD CONSTRAINT fk_reseller_ledger_invoice
                     FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL'
                );
            }
        },

        // The two Phase B tunables, seeded here rather than in Phase B so Phase A
        // can already report "how much of this is withdrawable" and "is it enough
        // to withdraw" without hard-coding either number.
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('reseller.payout_holding_days', '30')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('reseller.payout_minimum', '50.00')",
    ],
];
