<?php

declare(strict_types=1);

use CodeVault\Database;

// Pins the base currency's exchange_rate to 1.
//
// An exchange rate reads "units of this currency per 1 unit of the base
// currency", so the base currency's own rate is 1 by definition. Nothing in
// the schema enforced that. An install seeded USD=1 / NGN=1490 and later
// promoted NGN to default kept NGN's 1490, and every "convert from base"
// multiplication in the app then inflated an already-NGN amount by 1490:
// store prices, deposit limits, and — most visibly — the figure handed to the
// payment gateway. A ₦7,501.50 invoice reached Paystack's checkout as
// ₦11,177,235.
//
// Only the base row's rate changes. Non-base rates are genuine FX and are left
// alone, and no monetary column is touched — the stored amounts were always
// correct; it was the multiplier applied on top of them that was not.
//
// CurrencyRepository::setDefault() now resets the rate as part of promoting a
// currency, and CurrencyService::rateFor() pins it at read time regardless, so
// this state cannot come back.

return [
    'up' => [
        static function (Database $db): void {
            $base = $db->selectOne('SELECT id, code, exchange_rate FROM currencies WHERE is_default = 1 LIMIT 1');

            if ($base === null || abs((float) $base['exchange_rate'] - 1.0) < 0.00001) {
                return;
            }

            $db->update(
                'UPDATE currencies SET exchange_rate = 1.0000, updated_at = NOW() WHERE id = ?',
                [$base['id']]
            );
        },
    ],
];
