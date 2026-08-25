<?php

declare(strict_types=1);

// Repairs the currency symbols that make amounts render with the wrong
// currency in emails (and every other display surface).
//
// Every email path already resolves the *client's* currency correctly, but
// the SYMBOL stored in the currencies table was wrong for NGN — a plain "N"
// instead of the proper naira sign "₦" — so a Nigerian client's emails read
// "N28,339.00" instead of "₦28,339.00". EUR could also be stored corrupted
// on installs that imported it through a non-UTF-8 path.
//
// The symbols are written via CONVERT(<utf-8 hex>) so the result is correct
// regardless of the migration runner's connection charset — a literal '₦'
// in the SQL string can arrive as "?" if the client connection isn't UTF-8.
// Idempotent: each row is only touched when its symbol is not already the
// correct one, so re-running through the automatic migrator is a no-op.

return [
    'up' => [
        // The proper naira sign U+20A6 (₦), UTF-8 bytes E2 82 A6.
        // BINARY on the column side avoids an illegal-mix-of-collations
        // error when the stored column uses utf8mb4_general_ci but the
        // CONVERT()ed literal is utf8mb4_unicode_ci (MySQL 1267) — which
        // otherwise leaves this migration permanently stuck.
        <<<'SQL'
        UPDATE currencies SET symbol = CONVERT(0xE282A6 USING utf8mb4), updated_at = NOW()
        WHERE code = 'NGN' AND BINARY symbol <> CONVERT(0xE282A6 USING utf8mb4)
        SQL,
        // The proper euro sign U+20AC (€), UTF-8 bytes E2 82 AC.
        <<<'SQL'
        UPDATE currencies SET symbol = CONVERT(0xE282AC USING utf8mb4), updated_at = NOW()
        WHERE code = 'EUR' AND BINARY symbol <> CONVERT(0xE282AC USING utf8mb4)
        SQL,
        // The dollar sign (defensive — any non-"$" value would be data rot).
        <<<'SQL'
        UPDATE currencies SET symbol = '$', updated_at = NOW()
        WHERE code = 'USD' AND BINARY symbol <> '$'
        SQL,
    ],
];
