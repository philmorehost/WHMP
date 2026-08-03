<?php

declare(strict_types=1);

use CodeVault\Database;

/**
 * `cancellation_requests` was created twice, with two different shapes.
 *
 * Migration 0113 created it with (type, reason, status ENUM('pending',
 * 'processed')). Migration 0118 later declared the shape the code actually
 * uses — client_id, cancellation_type, cancel_date, admin_notes, reviewed_by,
 * reviewed_at, completed_at and a four-value status enum — but it opens with
 * `CREATE TABLE IF NOT EXISTS`, so on every install that had already run 0113
 * it silently did nothing and the 0113 shape stayed.
 *
 * CancellationRequestRepository papered over the gap with an ensureSchema()
 * method that fired ALTER TABLE ... ADD COLUMN inside try/catch on the insert
 * path. That only ran when a request was *created*, so the cron path
 * (findDueCancellations -> markCompleted) hit the un-patched table and failed
 * on `cr.cancellation_type`. It also never touched the status enum, so
 * approve()/markCompleted() writing 'approved'/'completed' would be coerced to
 * '' on a non-strict server and rejected outright on a strict one.
 *
 * This migration converges both shapes on 0118's, idempotently, so it is a
 * no-op on installs that got the 0118 table and a repair everywhere else.
 * INFORMATION_SCHEMA probes rather than `IF NOT EXISTS` for the reason spelled
 * out in migration 0120: that syntax is MariaDB/MySQL-8.0.29+ only and makes
 * the whole statement a syntax error on older hosts.
 */
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

$columnExists = static function (Database $db, string $table, string $column): bool {
    return $db->selectOne(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    ) !== null;
};

$indexExists = static function (Database $db, string $table, string $index): bool {
    return $db->selectOne(
        'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [$table, $index]
    ) !== null;
};

return [
    'up' => [
        // Nothing below can run if the table was never created at all (a
        // fresh install runs 0113 first, so in practice it always exists).
        static function (Database $db) use ($addColumnIfMissing): void {
            $table = $db->selectOne(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                ['cancellation_requests']
            );

            if ($table === null) {
                return;
            }

            $addColumnIfMissing($db, 'cancellation_requests', 'client_id', 'client_id INT UNSIGNED NULL AFTER service_id');
            $addColumnIfMissing($db, 'cancellation_requests', 'cancellation_type', "cancellation_type ENUM('immediate', 'due_date') NOT NULL DEFAULT 'immediate' AFTER client_id");
            $addColumnIfMissing($db, 'cancellation_requests', 'cancel_date', 'cancel_date DATE NULL AFTER cancellation_type');
            $addColumnIfMissing($db, 'cancellation_requests', 'admin_notes', 'admin_notes TEXT NULL AFTER reason');
            $addColumnIfMissing($db, 'cancellation_requests', 'reviewed_by', 'reviewed_by INT UNSIGNED NULL AFTER status');
            $addColumnIfMissing($db, 'cancellation_requests', 'reviewed_at', 'reviewed_at DATETIME NULL AFTER reviewed_by');
            $addColumnIfMissing($db, 'cancellation_requests', 'completed_at', 'completed_at DATETIME NULL AFTER reviewed_at');
        },

        // 0118's supporting indexes. The service_id one has to exist before the
        // unique key below can go: that key is (service_id, status), so it is
        // currently the only index backing fk_cancellation_requests_service and
        // InnoDB refuses to drop an index a foreign key still depends on
        // (errno 1553). Creating this one first gives the constraint somewhere
        // else to land.
        static function (Database $db) use ($indexExists): void {
            $indexes = [
                'idx_cancellation_requests_service' => 'service_id',
                'idx_cancellation_requests_client' => 'client_id',
                'idx_cancellation_requests_status' => 'status',
                'idx_cancellation_requests_cancel_date' => 'cancel_date',
            ];

            foreach ($indexes as $name => $column) {
                if ($indexExists($db, 'cancellation_requests', $name)) {
                    continue;
                }

                $db->statement("ALTER TABLE cancellation_requests ADD INDEX {$name} ({$column})");
            }
        },

        // The 0113 unique key spans (service_id, status). Under the four-value
        // status enum that permits only one request per service per status,
        // which would reject a client's second cancellation request for the
        // same service after an earlier one was rejected. 0118 has no such key.
        static function (Database $db) use ($indexExists): void {
            if (!$indexExists($db, 'cancellation_requests', 'uq_service_pending')) {
                return;
            }

            $db->statement('ALTER TABLE cancellation_requests DROP INDEX uq_service_pending');
        },

        // Backfill the new columns from the 0113 ones before the status enum
        // is widened, so no request is stranded in a value the enum drops.
        static function (Database $db) use ($columnExists): void {
            if (!$columnExists($db, 'cancellation_requests', 'type')) {
                return;
            }

            $db->statement(
                "UPDATE cancellation_requests
                 SET cancellation_type = CASE WHEN type = 'end_of_period' THEN 'due_date' ELSE 'immediate' END
                 WHERE type IS NOT NULL AND type <> ''"
            );
        },

        static function (Database $db): void {
            $db->statement(
                'UPDATE cancellation_requests cr
                 JOIN services s ON cr.service_id = s.id
                 SET cr.client_id = s.client_id
                 WHERE cr.client_id IS NULL'
            );
        },

        // Widen the status enum. The legacy 'processed' maps to 'completed';
        // it must be carried across as a string while both values are legal,
        // hence the temporary five-value enum.
        static function (Database $db): void {
            $status = $db->selectOne(
                'SELECT COLUMN_TYPE AS column_type FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['cancellation_requests', 'status']
            );

            if ($status === null || str_contains((string) $status['column_type'], "'completed'")) {
                return;
            }

            $db->statement(
                "ALTER TABLE cancellation_requests
                 MODIFY COLUMN status ENUM('pending', 'processed', 'approved', 'rejected', 'completed')
                 NOT NULL DEFAULT 'pending'"
            );
            $db->statement("UPDATE cancellation_requests SET status = 'completed' WHERE status = 'processed'");
            $db->statement(
                "ALTER TABLE cancellation_requests
                 MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'completed')
                 NOT NULL DEFAULT 'pending'"
            );
        },

        // The 0113 `type` column is NOT NULL with no default and the repository
        // never writes it, so every insert depends on the server being
        // non-strict. Make it nullable rather than dropping it, so an install
        // that still has readers of the old column keeps its data.
        static function (Database $db) use ($columnExists): void {
            if (!$columnExists($db, 'cancellation_requests', 'type')) {
                return;
            }

            $db->statement(
                "ALTER TABLE cancellation_requests MODIFY COLUMN type ENUM('immediate', 'end_of_period') NULL"
            );
        },
    ],
];
