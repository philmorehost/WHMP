<?php

declare(strict_types=1);

use CodeVault\Database;

// Lets a marketing campaign target plain email addresses that aren't clients
// (press lists, prospects, partners) alongside the existing group/individual
// targeting.
//
// Three changes are needed because the recipient row was modelled as "a
// client": mail_campaign_recipients.client_id is NOT NULL, so an external
// address has nothing to point at, and there was nowhere to record the
// address itself.
//
//  1. mail_campaigns.external_emails  — the admin's pasted address list.
//  2. mail_campaign_recipients.email  — the address for a non-client row, so
//     open-tracking and the per-campaign recipient list still work.
//  3. client_id becomes NULLABLE       — the FK to clients stays in place;
//     a NULL simply doesn't participate in it, so client rows keep their
//     referential guarantee while external rows are allowed to have none.
//
// Existence is checked through INFORMATION_SCHEMA rather than
// "ADD COLUMN IF NOT EXISTS" (MariaDB-only syntax — see 0120), so this is
// portable and safe to re-apply under the automatic on-boot migrator.

$addColumnIfMissing = static function (Database $db, string $table, string $column, string $definition): void {
    $exists = $db->selectOne(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );

    if ($exists !== null) {
        return;
    }

    $db->statement("ALTER TABLE {$table} ADD COLUMN {$definition}");
};

$makeNullableIfNeeded = static function (Database $db, string $table, string $column, string $definition): void {
    $row = $db->selectOne(
        'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );

    // Missing column, or already nullable — nothing to do either way.
    if ($row === null || strtoupper((string) $row['IS_NULLABLE']) === 'YES') {
        return;
    }

    $db->statement("ALTER TABLE {$table} MODIFY {$definition}");
};

return [
    'up' => [
        static fn (Database $db) => $addColumnIfMissing(
            $db,
            'mail_campaigns',
            'external_emails',
            'external_emails TEXT NULL AFTER client_id'
        ),
        static fn (Database $db) => $addColumnIfMissing(
            $db,
            'mail_campaign_recipients',
            'email',
            'email VARCHAR(191) NULL AFTER client_id'
        ),
        static fn (Database $db) => $makeNullableIfNeeded(
            $db,
            'mail_campaign_recipients',
            'client_id',
            'client_id INT UNSIGNED NULL'
        ),
    ],
];
