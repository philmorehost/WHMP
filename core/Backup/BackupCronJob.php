<?php

declare(strict_types=1);

namespace CodeVault\Backup;

use CodeVault\Cron\CronJob;
use CodeVault\Settings\SettingsRepository;

final class BackupCronJob implements CronJob
{
    private const DEFAULT_HOURS = 24;

    public function __construct(
        private readonly BackupService $backups,
        private readonly SettingsRepository $settings
    ) {
    }

    public function name(): string
    {
        return 'daily-backup';
    }

    /**
     * How often to back up, from the admin's setting.
     *
     * Read per call rather than hardcoded, so changing the schedule takes
     * effect on the next cron tick. A blank or non-numeric value falls back to
     * daily — reading it as 0 would make the job due on every tick, which is
     * the failure this change exists to stop.
     */
    public function frequencyMinutes(): int
    {
        $hours = $this->settings->get('backup.frequency_hours', (string) self::DEFAULT_HOURS);

        if ($hours === null || trim((string) $hours) === '' || !is_numeric($hours)) {
            $hours = self::DEFAULT_HOURS;
        }

        // Floor of one hour: anything shorter is a mistake on a job that writes
        // a full database dump plus a zip of the whole install.
        return max(60, (int) round(((float) $hours) * 60));
    }

    public function handle(): void
    {
        $this->backups->run();
    }
}
