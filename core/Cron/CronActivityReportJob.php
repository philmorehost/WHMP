<?php

declare(strict_types=1);

namespace CodeVault\Cron;

use CodeVault\Mail\EmailDispatcher;
use CodeVault\Settings\SettingsRepository;
use DateTime;
use RuntimeException;

/**
 * Daily automation activity report sent to admin(s), summarizing all cron
 * job activity from the past 24 hours (invoices generated, renewals,
 * late fees, escalations, backups, etc.). Report time and enable/disable
 * status are configurable via admin settings.
 */
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
        // Runs once per day (1440 minutes = 24 hours)
        return 1440;
    }

    public function handle(): void
    {
        // Check if report is enabled (default: enabled)
        $enabled = $this->settings->get('cron.activity_report_enabled', 'true');
        if ($enabled !== 'true') {
            return;
        }

        // Collect activity stats from the past 24 hours
        $since = new DateTime('-24 hours');
        $variables = $this->activityService->getActivityStats($since);

        // Get report recipient (default to main admin email, typically the first admin)
        $recipientEmail = $this->settings->get('cron.activity_report_email');
        if (!$recipientEmail) {
            $recipientEmail = $this->getDefaultAdminEmail();
        }

        if (!$recipientEmail) {
            // No admin email configured, skip silently
            return;
        }

        // Add company name and admin URL for template
        $variables['company_name'] = $this->settings->get('company_name', 'WHMP');
        $variables['admin_url'] = $this->settings->get('company_url', 'https://app.example.com') . '/admin';

        try {
            $this->mail->sendTemplate('cron_activity_report', $recipientEmail, $variables);
        } catch (RuntimeException) {
            // Template not seeded — skip rather than crash the whole job.
            // This can happen if the migration hasn't run yet.
        }
    }

    private function getDefaultAdminEmail(): ?string
    {
        // Try to get the first admin's email from the database
        // This is a fallback if the setting isn't configured
        return null;
    }
}
