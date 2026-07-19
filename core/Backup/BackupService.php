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
    public function __construct(
        private readonly BackupManager $backups,
        private readonly BackupRunRepository $runs,
        private readonly string $rootPath,
        private readonly string $backupDir
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
        } catch (Throwable $e) {
            $this->runs->fail($runId, $e->getMessage());
        }

        return $runId;
    }
}
