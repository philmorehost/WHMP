<?php

declare(strict_types=1);

namespace CodeVault\Gdpr;

use CodeVault\Settings\SettingsRepository;

/**
 * Admin-configurable retention-day thresholds for DataPruningJob — a thin
 * wrapper over the existing key/value SettingsRepository, same pattern as
 * ThemeSettings, rather than a dedicated table for three integers.
 */
final class GdprSettings
{
    private const ACTIVITY_LOG_DAYS_KEY = 'gdpr.retention_days_activity_log';
    private const LOGIN_ATTEMPTS_DAYS_KEY = 'gdpr.retention_days_login_attempts';
    private const EMAIL_LOG_DAYS_KEY = 'gdpr.retention_days_email_log';

    private const DEFAULT_ACTIVITY_LOG_DAYS = 730;
    private const DEFAULT_LOGIN_ATTEMPTS_DAYS = 90;
    private const DEFAULT_EMAIL_LOG_DAYS = 365;

    public function __construct(
        private readonly SettingsRepository $settings
    ) {
    }

    /** @return array{activityLogDays: int, loginAttemptsDays: int, emailLogDays: int} */
    public function get(): array
    {
        return [
            'activityLogDays' => $this->readInt(self::ACTIVITY_LOG_DAYS_KEY, self::DEFAULT_ACTIVITY_LOG_DAYS),
            'loginAttemptsDays' => $this->readInt(self::LOGIN_ATTEMPTS_DAYS_KEY, self::DEFAULT_LOGIN_ATTEMPTS_DAYS),
            'emailLogDays' => $this->readInt(self::EMAIL_LOG_DAYS_KEY, self::DEFAULT_EMAIL_LOG_DAYS),
        ];
    }

    public function save(int $activityLogDays, int $loginAttemptsDays, int $emailLogDays): void
    {
        $this->settings->set(self::ACTIVITY_LOG_DAYS_KEY, (string) max(1, $activityLogDays));
        $this->settings->set(self::LOGIN_ATTEMPTS_DAYS_KEY, (string) max(1, $loginAttemptsDays));
        $this->settings->set(self::EMAIL_LOG_DAYS_KEY, (string) max(1, $emailLogDays));
    }

    private function readInt(string $key, int $default): int
    {
        $value = $this->settings->get($key);

        return $value !== null && $value !== '' ? (int) $value : $default;
    }
}
