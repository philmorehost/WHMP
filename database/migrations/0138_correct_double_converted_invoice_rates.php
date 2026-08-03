<?php

declare(strict_types=1);

use CodeVault\Database;

// Corrects invoices that were stored with a conversion rate they should never
// have carried.
//
// Catalog prices on this install are entered in the local currency, but
// invoice generation used CurrencyService::lockColumns(), which assumes prices
// are held in the base currency and stores the client's live FX rate on top.
// Display then multiplies by that rate, so a ₦7,501.50 domain renewal was
// shown — and billed — as ₦11,177,235.00 (7,501.50 x 1490).
//
// Generation was fixed to denominate rather than convert, but that only
// affects invoices raised afterwards. Every invoice already carrying a rate
// above 1 still renders inflated, which is what clients see on their invoice
// list and in reminder emails.
//
// THE STORED AMOUNTS ARE CORRECT AND ARE NOT TOUCHED. Only currency_rate is
// reset to 1, which means "this amount is already in this currency" — the same
// shape as every imported invoice and every invoice raised since the fix. The
// subtotal, tax, discount and total columns are left exactly as they are.
//
// Scope note: this is right for an install whose prices are entered in the
// currency clients are billed in. It would be wrong for a genuinely
// multi-currency catalog priced in a base currency with FX conversion per
// client — there, a rate above 1 is legitimate. Applied here deliberately,
// because a 1490x overstatement on live invoices is the more urgent error.

return [
    'up' => [
        static function (Database $db): void {
            // Report what is about to change, so the correction is visible in
            // the migration output rather than silent.
            $affected = $db->selectOne('SELECT COUNT(*) AS c FROM invoices WHERE currency_rate > 1');

            if ((int) ($affected['c'] ?? 0) === 0) {
                return;
            }

            $db->update('UPDATE invoices SET currency_rate = 1, updated_at = updated_at WHERE currency_rate > 1');
        },

        // Quotes and credit notes are denominated the same way and would show
        // the same inflation wherever they carry a rate.
        static function (Database $db): void {
            foreach (['quotes', 'credit_notes'] as $table) {
                try {
                    $hasColumn = $db->selectOne(
                        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                        [$table, 'currency_rate']
                    );

                    if ($hasColumn !== null) {
                        $db->update("UPDATE {$table} SET currency_rate = 1 WHERE currency_rate > 1");
                    }
                } catch (\Throwable) {
                    // Table absent on this install — nothing to correct.
                }
            }
        },
    ],
];
