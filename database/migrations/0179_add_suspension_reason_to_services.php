<?php

declare(strict_types=1);

use CodeVault\Database;

// Records why a service was suspended so the admin Services list can show it
// at a glance and the service page can explain the suspension without the
// admin having to dig through the activity log.
//
// Set on suspension (manual suspend / manual status change / the automatic
// overdue-invoice sweep) and cleared when the service leaves the suspended
// state, so a stale "overdue" note never lingers on a reactivated service.
//
// Existence is checked through INFORMATION_SCHEMA rather than
// "ADD COLUMN IF NOT EXISTS" (MariaDB-only syntax — see 0120), so this is
// portable and safe to re-apply under the automatic on-boot migrator.

return [
    'up' => [
        static function (Database $db): void {
            $exists = $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['services', 'suspension_reason']
            );

            if ($exists !== null) {
                return;
            }

            $db->statement('ALTER TABLE services ADD COLUMN suspension_reason VARCHAR(255) NULL AFTER status');
        },
    ],
];
