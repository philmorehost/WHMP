<?php

declare(strict_types=1);

namespace CodeVault\Backup;

use CodeVault\Cron\CronJob;

final class BackupCronJob implements CronJob
{
    public function __construct(
        private readonly BackupService $backups
    ) {
    }

    public function name(): string
    {
        return 'daily-backup';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        $this->backups->run();
    }
}
