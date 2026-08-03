<?php

declare(strict_types=1);

// Durable last-run times for the cron scheduler.
//
// The scheduler decides whether a job is due by comparing "now" against the
// last time it ran, and kept that state in storage/cache/cron-state.json. The
// write used file_put_contents() with no error check, so on a host where
// storage/ isn't writable — a very common shared-hosting permission setup —
// the state silently never persisted. Every job then looked overdue on every
// tick, which is why backups were being taken every minute rather than daily.
//
// The database is by definition writable (the app is using it), so last-run
// state lives here now, with the JSON file kept as a fallback for installs
// that run the cron before this table exists.
//
// Also seeds the backup schedule/retention settings the admin can now control.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS cron_job_state (
            job_name VARCHAR(191) NOT NULL PRIMARY KEY,
            last_run_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,

        <<<'SQL'
        INSERT IGNORE INTO settings (`key`, `value`, updated_at) VALUES
            ('backup.frequency_hours', '24', NOW()),
            ('backup.keep_count', '7', NOW())
        SQL,
    ],
];
