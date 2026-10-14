<?php

declare(strict_types=1);

// The reseller payout minimum moves from $50 to $10, and every other currency's
// minimum is DERIVED from it through a conversion rate.
//
// THE NEW MODEL (see ResellerLedgerService::payoutMinimumForCurrency)
//
//   reseller.payout_minimum_amount    '10.00'  the threshold, in ...
//   reseller.payout_minimum_currency  'USD'    ... this anchor currency
//   reseller.payout_minimum_rates     '{}'     optional per-currency conversion
//                                              rates: 1 anchor unit = N units of
//                                              that currency. A currency with no
//                                              entry follows the live exchange
//                                              rate from /admin/currencies.
//
// So NGN's minimum is 10 x (the NGN rate), EUR's is 10 x (the EUR rate), and
// changing the one $10 figure moves every currency with it — instead of the old
// per-currency nominal amounts, which silently drifted away from each other.
//
// WHY THE OLD KEYS ARE RETIRED RATHER THAN READ
//
// `reseller.payout_minimum` was a BASE-unit amount ('50.00'), and on an install whose
// default currency is NGN that meant ₦50, not $50. `reseller.payout_minimums` held
// nominal amounts that the admin form wrote for EVERY currency on every save — so
// almost any install that had touched the form has `{"USD":"50.00","NGN":"..."}`
// pinned in place, which would keep overriding the new $10 figure forever. Both are
// cleared so the new figure is the one rule in force.
//
// The anchor is USD when the install has a USD currency, otherwise the default
// currency (the "$10" then reads as 10 units of whatever the base is, and the admin
// can change it on /admin/resellers/accounts).

return [
    'up' => [
        function (\CodeVault\Database $db): void {
            $set = static function (\CodeVault\Database $db, string $key, string $value): void {
                $existing = $db->selectOne('SELECT `key` FROM settings WHERE `key` = ?', [$key]);

                if ($existing === null) {
                    $db->insert('INSERT INTO settings (`key`, `value`) VALUES (?, ?)', [$key, $value]);

                    return;
                }

                $db->update('UPDATE settings SET `value` = ? WHERE `key` = ?', [$value, $key]);
            };

            $anchor = 'USD';

            try {
                $usd = $db->selectOne("SELECT code FROM currencies WHERE UPPER(code) = 'USD' LIMIT 1");

                if ($usd === null) {
                    $default = $db->selectOne('SELECT code FROM currencies WHERE is_default = 1 LIMIT 1');
                    $anchor = strtoupper(trim((string) ($default['code'] ?? 'USD'))) ?: 'USD';
                }
            } catch (\Throwable) {
                // No currencies table (a very old or partial schema): USD is the
                // documented default, and the admin page can change it.
            }

            $set($db, 'reseller.payout_minimum_amount', '10.00');
            $set($db, 'reseller.payout_minimum_currency', $anchor);
            $set($db, 'reseller.payout_minimum_rates', '{}');

            $db->delete("DELETE FROM settings WHERE `key` IN ('reseller.payout_minimum', 'reseller.payout_minimums')");
        },
    ],
];
