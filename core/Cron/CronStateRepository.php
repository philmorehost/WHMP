<?php

declare(strict_types=1);

namespace CodeVault\Cron;

use CodeVault\Database;
use DateTimeImmutable;
use Throwable;

/**
 * Durable last-run times for the scheduler.
 *
 * Replaces storage/cache/cron-state.json as the primary store. That file was
 * written without checking the result, so on a host where storage/ isn't
 * writable the state vanished every tick and every job looked permanently due
 * — the cause of backups running every minute instead of daily.
 *
 * Reads and writes are best-effort: if this table doesn't exist yet (a cron
 * running before the migrator has caught up), the scheduler falls back to the
 * JSON file rather than failing the whole run.
 */
final class CronStateRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, int>|null null when the store is unavailable */
    public function load(): ?array
    {
        try {
            $state = [];

            foreach ($this->db->select('SELECT job_name, last_run_at FROM cron_job_state') as $row) {
                $state[(string) $row['job_name']] = (int) $row['last_run_at'];
            }

            return $state;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Records one job's last-run time immediately.
     *
     * Called as each job finishes rather than once at the end of the sweep. A
     * cron run that is killed part-way — a slow backup hitting the host's
     * max_execution_time is the usual cause — would otherwise record nothing
     * at all, so every job looked due again a minute later and the same slow
     * job was started over and over, never finishing and never being marked.
     */
    public function saveOne(string $job, int $ranAt): bool
    {
        return $this->save([$job => $ranAt]);
    }

    /**
     * @param array<string, int> $state
     * @return bool false when the store is unavailable, so the caller can fall back
     */
    public function save(array $state): bool
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            foreach ($state as $job => $ranAt) {
                $this->db->insert(
                    'INSERT INTO cron_job_state (job_name, last_run_at, updated_at) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), updated_at = VALUES(updated_at)',
                    [(string) $job, max(0, (int) $ranAt), $now]
                );
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
