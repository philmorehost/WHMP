<?php

declare(strict_types=1);

use CodeVault\Database;

// Gives billable items an explicit lifecycle so the admin (and the client)
// can cancel one that has not been rolled into an invoice yet, and so a
// cancelled item is never picked up by BillableItemInvoicingJob.
//
//   pending   — queued; will be invoiced on the next cron sweep
//   invoiced  — already turned into an invoice (invoice_id is set)
//   cancelled — withdrawn by the admin or the client; never invoiced
//
// Existing rows are backfilled: anything that already has an invoice_id is
// 'invoiced', everything else stays 'pending' (the column default).
//
// Existence is checked through INFORMATION_SCHEMA rather than
// "ADD COLUMN IF NOT EXISTS" (MariaDB-only syntax — see 0120), so this is
// portable and safe to re-apply under the automatic on-boot migrator.

$addColumnIfMissing = static function (Database $db, string $column, string $definition): void {
    $exists = $db->selectOne(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['billable_items', $column]
    );

    if ($exists !== null) {
        return;
    }

    $db->statement("ALTER TABLE billable_items ADD COLUMN {$definition}");
};

return [
    'up' => [
        static function (Database $db) use ($addColumnIfMissing): void {
            $addColumnIfMissing($db, 'status', "status ENUM('pending', 'invoiced', 'cancelled') NOT NULL DEFAULT 'pending' AFTER invoice_id");
            $addColumnIfMissing($db, 'cancelled_at', 'cancelled_at DATETIME NULL AFTER status');
            $addColumnIfMissing($db, 'cancelled_by', "cancelled_by ENUM('admin', 'client') NULL AFTER cancelled_at");
            $addColumnIfMissing($db, 'cancelled_reason', 'cancelled_reason VARCHAR(255) NULL AFTER cancelled_by');

            // Backfill so pre-existing invoiced rows report 'invoiced' rather
            // than the column default of 'pending'.
            $db->statement("UPDATE billable_items SET status = 'invoiced' WHERE invoice_id IS NOT NULL AND status <> 'invoiced'");
        },
    ],
];
