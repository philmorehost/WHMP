<?php

declare(strict_types=1);

namespace CodeVault\Cron;

use CodeVault\Mail\EmailDispatcher;
use CodeVault\Settings\SettingsRepository;
use DateTime;
use RuntimeException;

final class CronActivityReportJob implements CronJob
{
    public function __construct(
        private readonly CronActivityService $activityService,
        private readonly EmailDispatcher $mail,
        private readonly SettingsRepository $settings
    ) {
    }

    public function name(): string
    {
        return 'cron-activity-report';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        $enabled = $this->settings->get('automation.activity_report_enabled', 'true');
        if ($enabled !== 'true') {
            return;
        }

        $since = new DateTime('-24 hours');
        $variables = $this->activityService->getActivityStats($since);

        $recipientEmail = $this->settings->get('automation.activity_report_email');
        if (!$recipientEmail) {
            $recipientEmail = $this->getDefaultAdminEmail();
        }

        if (!$recipientEmail) {
            return;
        }

        $variables['company_name'] = $this->settings->get('company_name', 'WHMP');
        $variables['admin_url'] = $this->settings->get('company_url', 'https://app.example.com') . '/admin';

        try {
            $this->mail->sendTemplate('cron_activity_report', $recipientEmail, $variables);
        } catch (RuntimeException) {
            // Template not seeded yet
        }
    }

    private function getDefaultAdminEmail(): ?string
    {
        return null;
    }
}
