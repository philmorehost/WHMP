<?php

declare(strict_types=1);

use CodeVault\Database;

// `ADD COLUMN IF NOT EXISTS` is MySQL 8.0.29+/MariaDB-only syntax — this
// host's MySQL is older (see migration 0117's history of the same bug), so
// the whole ALTER statement is a syntax error there and never runs at all
// (not even partially), leaving every column below permanently missing no
// matter how many times the migration is retried. Each column is instead
// added by a closure that checks INFORMATION_SCHEMA.COLUMNS first, which
// works on every MySQL/MariaDB version.
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

return [
    'up' => [
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'whm_package_name', 'whm_package_name VARCHAR(191) NULL AFTER server_group_id'),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'pay_type', "pay_type VARCHAR(20) NOT NULL DEFAULT 'paid' AFTER type"),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'free_duration_type', "free_duration_type VARCHAR(50) NOT NULL DEFAULT 'lifetime' AFTER require_domain"),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'free_duration_days', 'free_duration_days INT NULL AFTER free_duration_type'),
    ],
];
