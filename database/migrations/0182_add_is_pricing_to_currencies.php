<?php

declare(strict_types=1);

use CodeVault\Database;

// Records WHICH currency the catalog prices are entered in.
//
// Every conversion in the app assumed the base/default currency was the one the
// admin typed prices into — CurrencyService's own docblock: "lockColumns()
// assumes catalog prices are held in the base currency and converts on top".
// That holds only while prices happen to be entered in the base currency.
//
// On this install prices are entered in naira while USD is the default, so the
// raw catalog figure was multiplied by the USD rate (1.0): a ₦22,350 plan was
// quoted, ordered, invoiced and reported as $22,350 — the naira number wearing
// a dollar sign, with no conversion applied at any point.
//
// is_pricing = 0 on every row is the pre-existing behaviour, because the flag
// falls back to the default currency — so this migration changes no figure on
// its own. The admin marks the pricing currency from /admin/currencies.

return [
    'up' => [
        static function (Database $db): void {
            $existing = $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['currencies', 'is_pricing']
            );

            if ($existing === null) {
                $db->statement('ALTER TABLE currencies ADD COLUMN is_pricing TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default');
            }
        },
    ],
];
