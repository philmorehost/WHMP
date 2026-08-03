<?php

declare(strict_types=1);

// Per-job cron run history, so the daily activity report can aggregate what
// actually happened across every cron tick in a 24h window. The scheduler
// itself still keeps its due-time state in a JSON file (it must work before
// any schema exists); this table is purely the reporting record, and the
// scheduler treats writing to it as best-effort.
//
// `stats` holds a small JSON object of counters a job chose to report
// (invoices_generated, late_fees_added, ...). JSON rather than columns
// because each job reports a different shape, and the report only ever sums
// keys it knows about.
//
// Every statement is IF NOT EXISTS / INSERT IGNORE so the automatic migrator
// that runs on boot can re-apply this safely.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS cron_job_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_name VARCHAR(191) NOT NULL,
            status ENUM('success', 'error') NOT NULL DEFAULT 'success',
            error_message TEXT NULL,
            stats TEXT NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            ran_at DATETIME NOT NULL,
            INDEX idx_cron_job_runs_ran_at (ran_at),
            INDEX idx_cron_job_runs_job_ran (job_name, ran_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,

        // Defaults mirror WHMCS: daily automation just after midnight, report
        // on, recipient left blank so CronActivityReportJob falls back to the
        // first admin account rather than emailing nowhere.
        <<<'SQL'
        INSERT IGNORE INTO settings (`key`, `value`, updated_at) VALUES
            ('automation.daily_run_time', '00:05', NOW()),
            ('automation.report_enabled', '1', NOW()),
            ('automation.report_email', '', NOW()),
            ('automation.last_report_date', '', NOW())
        SQL,
    ],
];
