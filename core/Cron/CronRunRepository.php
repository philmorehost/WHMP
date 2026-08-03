<?php

declare(strict_types=1);

namespace CodeVault\Cron;

use CodeVault\Database;
use DateTimeImmutable;
use Throwable;

/**
 * Read/write side of `cron_job_runs` — the reporting record of what each
 * cron job did, which the daily activity report aggregates over a 24h
 * window.
 *
 * Recording is best-effort by design: a cron run that did real work must not
 * be reported as failed just because the bookkeeping insert didn't land (the
 * table may not exist yet on a system booting for the first time, before the
 * automatic migrator has caught up).
 */
final class CronRunRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @param array<string, int> $stats */
    public function record(string $jobName, string $status, ?string $errorMessage, array $stats, int $durationMs): void
    {
        try {
            $this->db->insert(
                'INSERT INTO cron_job_runs (job_name, status, error_message, stats, duration_ms, ran_at) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $jobName,
                    $status === 'error' ? 'error' : 'success',
                    $errorMessage,
                    $stats === [] ? null : json_encode($stats),
                    max(0, $durationMs),
                    (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]
            );
        } catch (Throwable) {
            // Bookkeeping only — never let it break the actual cron run.
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function since(DateTimeImmutable $since): array
    {
        try {
            return $this->db->select(
                'SELECT job_name, status, error_message, stats, ran_at FROM cron_job_runs WHERE ran_at >= ? ORDER BY ran_at ASC',
                [$since->format('Y-m-d H:i:s')]
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Every stats key summed across the window, so a job that ran twelve
     * times in a day reports its total rather than its last run.
     *
     * @param array<int, array<string, mixed>> $runs
     * @return array<string, int>
     */
    public function sumStats(array $runs): array
    {
        $totals = [];

        foreach ($runs as $run) {
            if (empty($run['stats'])) {
                continue;
            }

            $decoded = json_decode((string) $run['stats'], true);

            if (!is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $key => $value) {
                if (!is_numeric($value)) {
                    continue;
                }

                $totals[(string) $key] = ($totals[(string) $key] ?? 0) + (int) $value;
            }
        }

        return $totals;
    }

    /**
     * Distinct failing jobs in the window, most recent message per job — the
     * report shows one line per broken job, not one per failed attempt.
     *
     * @param array<int, array<string, mixed>> $runs
     * @return array<int, array{job_name: string, error_message: string}>
     */
    public function errors(array $runs): array
    {
        $byJob = [];

        foreach ($runs as $run) {
            if (($run['status'] ?? '') !== 'error') {
                continue;
            }

            $byJob[(string) $run['job_name']] = (string) ($run['error_message'] ?? 'Unknown error');
        }

        $out = [];

        foreach ($byJob as $job => $message) {
            $out[] = ['job_name' => $job, 'error_message' => $message];
        }

        return $out;
    }

    /** Keeps the table from growing without bound; called from the report job. */
    public function pruneOlderThan(DateTimeImmutable $cutoff): void
    {
        try {
            $this->db->delete('DELETE FROM cron_job_runs WHERE ran_at < ?', [$cutoff->format('Y-m-d H:i:s')]);
        } catch (Throwable) {
            // Non-critical housekeeping.
        }
    }
}
