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
        // One backup at a time, enforced here and not just by the cron's
        // overlap lock: the cron lock stops two cron ticks from overlapping,
        // but this also covers a manual "Backup Now" racing an automated run.
        // Without it, two concurrent runs each write a full DB dump plus a
        // zip of the whole install — doubled CPU, disk and I/O for no extra
        // safety — and their pruners can race each other's files.
        $lock = $this->acquireRunLock();

        // null = another backup holds the lock right now: skip quietly. The
        // caller's schedule (or the admin's button) can try again later; two
        // simultaneous copies of the same backup protect nothing.
        if ($lock === null) {
            return 0;
        }

        try {
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
        } finally {
            // Always released — including on the failure paths — so one
            // crashed backup can't wedge every future run. (false = we never
            // got a lock file; nothing to release.)
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Takes the exclusive run lock (storage/backups/.backup.lock) without
     * blocking.
     *
     * @return resource|false|null the open lock handle to release, false when
     *         the lock file could not be created (run unlocked), null when
     *         another run holds the lock
     */
    private function acquireRunLock()
    {
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }

        $handle = @fopen($this->backupDir . '/.backup.lock', 'c');

        if ($handle === false) {
            // Unwritable backup dir — the backup itself would fail anyway;
            // run unlocked rather than not at all.
            error_log("BackupService: cannot open {$this->backupDir}/.backup.lock — running without the concurrency lock.");

            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * Emergency cleanup behind the admin's "Terminate running backups"
     * button: closes out every backup_runs row still marked "running" and
     * deletes the half-written dump/zip files such runs leave behind.
     *
     * Why rows can be stuck at all: a run marks its row complete/fail at the
     * END of its work, so a process killed mid-dump (OOM, reboot, the old
     * every-minute pile-up) leaves its row in running forever, plus partial
     * db_backup_*.sql / files_backup_*.zip files wasting disk.
     *
     * What "terminate" honestly means for a process that is executing right
     * now: PHP cannot signal another process portably, so the run itself is
     * not killed — but its history row is removed and its partial files are
     * deleted, and the caller is told via the returned "live" flag so the UI
     * can say the leftover process will simply finish on its own.
     *
     * @return array{terminated: int, files: int, live: bool} rows closed out,
     *         partial files deleted, and whether a live process was holding
     *         the run lock at the moment of the call
     */
    public function terminateRunning(): array
    {
        // Probe the run lock purely to detect a live process: acquiring it
        // means nothing is executing and every running row is a stale
        // leftover. When the probe LOSES the race, someone is mid-backup.
        // If we win it, we must let go again immediately or we'd block the
        // next real run.
        $lock = $this->acquireRunLock();
        $live = $lock === null;

        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        // The lock FILE itself is deliberately never deleted here. While a
        // live process holds its flock, unlinking the file would make the
        // next run lock a fresh inode and two backups could then run at
        // once — the exact pile-up this lock exists to prevent. A stale
        // file with no holder is harmless: the next run simply locks it.

        $rows = $this->runs->allRunning();

        $filesDeleted = 0;
        foreach ($rows as $row) {
            foreach ($this->partialArtefacts((string) ($row['started_at'] ?? '')) as $file) {
                if (@unlink($file)) {
                    $filesDeleted++;
                }
            }
        }

        $terminated = $this->runs->deleteByIds(array_column($rows, 'id'));

        return ['terminated' => $terminated, 'files' => $filesDeleted, 'live' => $live];
    }

    /**
     * Artefact files belonging to the run that started at $startedAt. A run
     * writes db_backup_<ts>.sql / files_backup_<ts>.zip where <ts> is set
     * moments AFTER the row's started_at, so the window below catches the
     * normal second-level skew — and never touches files from other, older
     * runs (whose history rows may already be pruned; file retention is
     * counted in copies, log retention in days).
     *
     * @return array<int, string>
     */
    private function partialArtefacts(string $startedAt): array
    {
        $start = strtotime($startedAt);

        if ($start === false) {
            return [];
        }

        $found = [];

        foreach (array_merge(
            (array) glob($this->backupDir . '/db_backup_*.sql'),
            (array) glob($this->backupDir . '/files_backup_*.zip'),
        ) as $path) {
            $basename = (string) basename((string) $path);

            if (preg_match('/(?:db|files)_backup_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\./', $basename, $m) !== 1) {
                continue;
            }

            $fileTime = ($dt = \DateTimeImmutable::createFromFormat('Y-m-d_H-i-s', $m[1])) === false
                ? false
                : $dt->getTimestamp();

            // Window opens exactly AT started_at, never earlier: the run
            // stamps its files after the row exists, and a lookback would
            // reach the files of a healthy backup that finished just before
            // this run started — exactly what terminate must not delete.
            if ($fileTime !== false && $fileTime >= $start && $fileTime <= $start + 3600) {
                $found[] = (string) $path;
            }
        }

        return $found;
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

        // Two plain globs instead of one GLOB_BRACE pattern: GLOB_BRACE is a
        // non-portable flag — musl/Alpine PHP builds are compiled without it
        // and throw "undefined constant" on use, so pruning would fatal (and
        // take the whole backup down with it) exactly there.
        $artefacts = array_merge(
            (array) glob($this->backupDir . '/db_backup_*.sql'),
            (array) glob($this->backupDir . '/files_backup_*.zip'),
        );

        foreach ($artefacts as $path) {
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
