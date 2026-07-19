<?php

declare(strict_types=1);

namespace CodeVault\Gdpr;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Cron\CronJob;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Security\LoginAttemptRepository;
use CodeVault\Security\PasswordResetTokenRepository;
use DateTimeImmutable;

/**
 * Data retention/GDPR pruning automation (blueprint §4.4) — before this job,
 * activity_log/security_login_attempts/email_log grew unboundedly forever;
 * nothing ever deleted an old row. Retention windows are admin-configurable
 * via GdprSettings rather than hardcoded, since "how long to keep this"
 * is a genuine compliance/business decision, not a code constant.
 */
final class DataPruningJob implements CronJob
{
    public function __construct(
        private readonly GdprSettings $settings,
        private readonly ActivityLogger $activity,
        private readonly LoginAttemptRepository $loginAttempts,
        private readonly EmailLogRepository $emailLog,
        private readonly PasswordResetTokenRepository $resetTokens
    ) {
    }

    public function name(): string
    {
        return 'gdpr-data-pruning';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        $retention = $this->settings->get();

        $this->activity->deleteOlderThan($this->daysAgo($retention['activityLogDays']));
        $this->loginAttempts->deleteOlderThan($this->daysAgo($retention['loginAttemptsDays']));
        $this->emailLog->deleteOlderThan($this->daysAgo($retention['emailLogDays']));
        $this->resetTokens->deleteExpired();
    }

    private function daysAgo(int $days): string
    {
        return (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
    }
}
