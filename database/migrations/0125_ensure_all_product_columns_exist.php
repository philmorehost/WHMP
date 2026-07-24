<?php

declare(strict_types=1);

use CodeVault\Database;

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
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'server_group_id', 'server_group_id INT UNSIGNED NULL AFTER product_group_id'),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'type', "type VARCHAR(50) NOT NULL DEFAULT 'other' AFTER status"),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'whm_package_name', 'whm_package_name VARCHAR(191) NULL AFTER server_group_id'),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'pay_type', "pay_type VARCHAR(20) NOT NULL DEFAULT 'paid' AFTER type"),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'free_duration_type', "free_duration_type VARCHAR(50) NOT NULL DEFAULT 'lifetime' AFTER pay_type"),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'free_duration_days', 'free_duration_days INT NULL AFTER free_duration_type'),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'autosetup', "autosetup VARCHAR(20) NOT NULL DEFAULT 'payment' AFTER whm_package_name"),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'is_upsell', "is_upsell TINYINT(1) NOT NULL DEFAULT 0 AFTER pay_type"),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'upsell_pitch', "upsell_pitch VARCHAR(255) NULL AFTER free_duration_days"),
        static fn (Database $db) => $addColumnIfMissing($db, 'products', 'require_domain', "require_domain TINYINT(1) NOT NULL DEFAULT 0 AFTER free_duration_days"),
    ],
];
