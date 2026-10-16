<?php

declare(strict_types=1);

namespace CodeVault\Backup;

use CodeVault\Cron\CronJob;
use CodeVault\Settings\SettingsRepository;

final class BackupCronJob implements CronJob
{
    private const DEFAULT_HOURS = 24;

    /** A backup writes a full DB dump plus a zip of the whole install — once per day is the fastest sane schedule. */
    private const MIN_MINUTES = 1440;

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
     * How often to back up, from the admin's setting — floored at once per
     * day. The 1-hour floor this job used to honour let a mistyped or
     * over-eager setting schedule a full database dump plus a zip of the
     * whole install up to 24 times a day, which is how "the backup system is
     * eating the server" happened; daily is now the fastest it can run.
     * Higher values (48 = every other day) still work for quieter hosts.
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

        return max(self::MIN_MINUTES, (int) round(((float) $hours) * 60));
    }

    /**
     * The admin's master switch for the automated backup system
     * (General Settings → "Enable automated backups"). Off means the cron
     * never registers this job — nothing is built, nothing runs, no log
     * noise. Manual "Backup Now" on the Backups page stays available: an
     * explicit click is not the schedule this switch turns off.
     */
    public function isEnabled(): bool
    {
        return $this->settings->get('backup.enabled', '1') !== '0';
    }

    public function handle(): void
    {
        // Defensive double-check: the entry point doesn't even register this
        // job while disabled, but a tick that raced a just-saved setting
        // still must not run a backup.
        if (!$this->isEnabled()) {
            return;
        }

        $this->backups->run();
    }
}
