<?php

declare(strict_types=1);

use CodeVault\Database;

/**
 * Widens every stored monetary column from DECIMAL(10,2) to DECIMAL(18,6).
 *
 * Stored amounts are authoritative in the system BASE currency (see
 * CurrencyService) and are converted up for display. At two decimal places a
 * base "cent" is worth a whole unit of the exchange rate in any high-rate
 * currency — with NGN at ~1490 that makes the smallest representable step
 * ~N14.90, so a N100 wallet top-up could only be stored as the nearest
 * representable base amount and rendered back as N104.30.
 *
 * Six decimal places puts the smallest step far below one minor unit of any
 * supported currency, so an amount entered in a display currency round-trips
 * back to itself exactly.
 *
 * This is a widening change only: every existing value keeps both its stored
 * digits and its meaning, so there is no backfill and nothing to reinterpret.
 * Exchange-rate columns move to DECIMAL(18,8) for the same reason — an
 * inverse rate such as 1/1490 is 0.00067114, which four decimals cannot hold.
 *
 * Each column is modified only if it actually exists and is still a decimal,
 * because migrations here do not run in a transaction (see Migrator) and a
 * failed ALTER against a table a given install never created would abort the
 * whole run.
 */
$widen = static function (Database $db, string $table, string $column, string $type): void {
    $row = $db->selectOne(
        'SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );

    if ($row === null || strtolower((string) $row['DATA_TYPE']) !== 'decimal') {
        return;
    }

    // MODIFY COLUMN restates the column whole, so nullability and default have
    // to be carried across explicitly or they are silently dropped.
    $isNullable = strtoupper((string) $row['IS_NULLABLE']) === 'YES';
    $nullSql = $isNullable ? 'NULL' : 'NOT NULL';

    $default = $row['COLUMN_DEFAULT'];
    $defaultSql = '';

    if ($default !== null && is_numeric($default)) {
        $defaultSql = ' DEFAULT ' . $default;
    } elseif ($default === null && $isNullable) {
        $defaultSql = ' DEFAULT NULL';
    }

    $db->statement("ALTER TABLE {$table} MODIFY COLUMN {$column} {$type} {$nullSql}{$defaultSql}");
};

// Amount-bearing columns. Percentage columns (discount_percent, tax rate,
// commission_rate, fraud_score) are deliberately excluded — they are not
// currency and gain nothing from the extra digits.
$moneyColumns = [
    'product_pricing' => ['price', 'setup_fee'],
    'configurable_option_pricing' => ['price'],
    'orders' => ['total', 'discount_amount'],
    'order_items' => ['unit_price', 'setup_fee'],
    'invoices' => ['subtotal', 'total', 'tax_amount', 'discount_amount'],
    'invoice_items' => ['amount'],
    'transactions' => ['amount'],
    'services' => ['amount'],
    'client_credit_ledger' => ['amount'],
    'domains' => ['amount'],
    'billable_items' => ['amount'],
    'affiliates' => ['balance'],
    'affiliate_commissions' => ['amount'],
    'affiliate_payout_requests' => ['amount'],
    'credit_notes' => ['total'],
    'credit_note_items' => ['amount'],
    'quotes' => ['total'],
    'quote_items' => ['amount'],
    'domain_pricing' => ['register_price', 'transfer_price', 'renew_price', 'redemption_fee'],
    'promotions' => ['value', 'min_order_amount'],
];

$rateColumns = [
    'currencies' => ['exchange_rate'],
    'orders' => ['currency_rate'],
    'invoices' => ['currency_rate'],
    'credit_notes' => ['currency_rate'],
    'quotes' => ['currency_rate'],
];

$up = [];

foreach ($moneyColumns as $table => $columns) {
    foreach ($columns as $column) {
        $up[] = static fn (Database $db) => $widen($db, $table, $column, 'DECIMAL(18,6)');
    }
}

foreach ($rateColumns as $table => $columns) {
    foreach ($columns as $column) {
        $up[] = static fn (Database $db) => $widen($db, $table, $column, 'DECIMAL(18,8)');
    }
}

return ['up' => $up];
