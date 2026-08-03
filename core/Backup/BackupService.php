<?php

declare(strict_types=1);

namespace CodeVault\Backup;

use CodeVault\Update\BackupManager;
use Throwable;

/**
 * Admin/cron-triggered backups (blueprint §5 "backup hooks") — wraps the
 * existing BackupManager (built in R1 for pre-OTA-update snapshots, pure
 * PHP DB dump + file zip, no mysqldump/exec dependency) with a tracked
 * history row per run, so staff can see what ran, when, and whether it
 * succeeded without digging through the filesystem.
 */
final class BackupService
{
    /** Backups retained when the admin hasn't set a limit. */
    private const DEFAULT_KEEP = 7;

    /** Backup run log rows older than this are pruned on every run, success or failure. */
    private const DEFAULT_LOG_RETENTION_DAYS = 1;

    public function __construct(
        private readonly BackupManager $backups,
        private readonly BackupRunRepository $runs,
        private readonly string $rootPath,
        private readonly string $backupDir,
        private readonly ?\CodeVault\Settings\SettingsRepository $settings = null
    ) {
    }

    public function run(): int
    {
        $runId = $this->runs->start();

        try {
            $result = $this->backups->createBackup($this->rootPath, $this->backupDir, ['storage/backups']);
            $sizeBytes = (is_file($result['sqlFile']) ? filesize($result['sqlFile']) : 0)
                + (is_file($result['filesZip']) ? filesize($result['filesZip']) : 0);

            $this->runs->complete($runId, $this->backupDir, (int) $sizeBytes);

            // Only prune backup FILES after a backup succeeds — never delete
            // old copies because a new one failed.
            $this->pruneOldBackups();
        } catch (Throwable $e) {
            $this->runs->fail($runId, $e->getMessage());
        }

        // Unlike the backup files above, log rows carry no data worth
        // protecting — they're just a history of past runs — so this prunes
        // unconditionally, on both the success and failure paths. Without
        // it backup_runs grew forever with no way to trim it, especially on
        // an hourly schedule.
        $this->pruneOldRunLogs();

        return $runId;
    }

    /** @return int rows deleted */
    public function pruneOldRunLogs(): int
    {
        $cutoff = (new \DateTimeImmutable('-' . $this->logRetentionDays() . ' days'))->format('Y-m-d H:i:s');

        return $this->runs->deleteOlderThan($cutoff);
    }

    private function logRetentionDays(): int
    {
        if ($this->settings === null) {
            return self::DEFAULT_LOG_RETENTION_DAYS;
        }

        $value = $this->settings->get('backup.log_retention_days', (string) self::DEFAULT_LOG_RETENTION_DAYS);

        // Blank/non-numeric means unset; floored at 1 so a stray 0 can't wipe
        // the log on every single run, mirroring keepCount()'s floor below.
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return self::DEFAULT_LOG_RETENTION_DAYS;
        }

        return max(1, (int) $value);
    }

    /**
     * Keeps the newest N backups and deletes the rest.
     *
     * Nothing pruned these before, so every run added a full database dump and
     * a zip of the entire install and left them there permanently — on a daily
     * schedule that is slow growth, but while the scheduler was firing every
     * minute it would fill the disk within days.
     *
     * Files are paired by their timestamped names (db_backup_<ts>.sql and
     * files_backup_<ts>.zip), so a set is only removed once both parts are
     * past the retention window.
     *
     * @return int files deleted
     */
    public function pruneOldBackups(): int
    {
        $keep = $this->keepCount();

        if ($keep <= 0 || !is_dir($this->backupDir)) {
            return 0;
        }

        // Group both artefacts of a run under its timestamp.
        $sets = [];

        foreach ((array) glob($this->backupDir . '/{db_backup_*.sql,files_backup_*.zip}', GLOB_BRACE) as $path) {
            if (!is_file($path)) {
                continue;
            }

            if (preg_match('/(?:db|files)_backup_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\./', basename($path), $m) !== 1) {
                continue;
            }

            $sets[$m[1]][] = $path;
        }

        if (count($sets) <= $keep) {
            return 0;
        }

        // Timestamped names sort chronologically, so newest last.
        krsort($sets);

        $deleted = 0;

        foreach (array_slice(array_keys($sets), $keep) as $oldTimestamp) {
            foreach ($sets[$oldTimestamp] as $path) {
                if (@unlink($path)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function keepCount(): int
    {
        if ($this->settings === null) {
            return self::DEFAULT_KEEP;
        }

        $value = $this->settings->get('backup.keep_count', (string) self::DEFAULT_KEEP);

        // Blank/non-numeric means unset, not "keep zero" — reading it as 0
        // would delete every backup on the next successful run.
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return self::DEFAULT_KEEP;
        }

        return max(1, (int) $value);
    }
}
