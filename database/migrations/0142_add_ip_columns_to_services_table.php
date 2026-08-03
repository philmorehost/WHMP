<?php

declare(strict_types=1);

use CodeVault\Database;

// Makes services.dedicated_ip / assigned_ips real schema.
//
// Both columns were being created at runtime by
// ServiceRepository::ensureSchema(), which fires only when an admin saves the
// service-details form. Until someone happened to do that, the columns did not
// exist — so a fresh install had a `services` table that did not match what the
// admin view, the client service panel, the WHMCS importer and now the
// server-details email all read. `SELECT s.*` hid the problem; anything naming
// the columns explicitly would not.
//
// ensureSchema() is deliberately left in place: it is what repairs installs
// that already ran without these columns, and both paths are idempotent.

/** Adds a column only when it isn't already there — the runtime ALTER may have won the race. */
$addColumn = static function (Database $db, string $column, string $definition): void {
    $exists = $db->selectOne(
        'SELECT 1 AS y FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['services', $column]
    );

    if ($exists !== null) {
        return;
    }

    $db->statement("ALTER TABLE services ADD COLUMN {$definition}");
};

return [
    'up' => [
        static function (Database $db) use ($addColumn): void {
            $addColumn($db, 'dedicated_ip', 'dedicated_ip VARCHAR(255) NULL AFTER username');
        },
        static function (Database $db) use ($addColumn): void {
            $addColumn($db, 'assigned_ips', 'assigned_ips TEXT NULL AFTER dedicated_ip');
        },
    ],
];
