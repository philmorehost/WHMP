<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Cron\CronJob;
use CodeVault\Cron\ReportsCronStats;
use CodeVault\Settings\SettingsRepository;

/**
 * Drains queued campaign emails a few at a time.
 *
 * "Send Now" only writes the recipient list; this job does the sending, so a
 * campaign to hundreds of clients leaves as a steady trickle rather than a
 * burst that trips the mail host's rate limit or the PHP time limit.
 *
 * Runs every minute off the same single cron entry as everything else. At the
 * default of 5 per run that's 300 emails an hour, and the batch size is an
 * admin setting for hosts that allow more.
 */
final class CampaignDispatchJob implements CronJob, ReportsCronStats
{
    private const DEFAULT_BATCH = 5;

    /** @var array<string, int> counters for the daily activity report */
    private array $stats = [];

    public function __construct(
        private readonly MailCampaignService $campaigns,
        private readonly SettingsRepository $settings
    ) {
    }

    public function name(): string
    {
        return 'campaign-dispatch';
    }

    public function frequencyMinutes(): int
    {
        return 1;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    public function handle(): void
    {
        $this->stats = ['email_campaigns' => 0];

        $this->stats['email_campaigns'] = $this->campaigns->dispatchQueued($this->batchSize());
    }

    private function batchSize(): int
    {
        $raw = $this->settings->get('marketing.campaign_batch_size', (string) self::DEFAULT_BATCH);

        // A blank or non-numeric setting means "unset", not "zero" — reading it
        // as 0 would stall every campaign permanently.
        if ($raw === null || trim((string) $raw) === '' || !is_numeric($raw)) {
            return self::DEFAULT_BATCH;
        }

        return max(1, min(500, (int) $raw));
    }
}
