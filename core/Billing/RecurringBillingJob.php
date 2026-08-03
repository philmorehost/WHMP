<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;
use CodeVault\Cron\ReportsCronStats;

final class RecurringBillingJob implements CronJob, ReportsCronStats
{
    /** @var array<string, int> counters for the daily activity report */
    private array $stats = [];

    public function __construct(
        private readonly RecurringBillingService $billing
    ) {
    }

    public function name(): string
    {
        return 'recurring-billing';
    }

    public function frequencyMinutes(): int
    {
        // Once a day is standard for invoice generation (blueprint §3 "the
        // single system cron"); the job itself is safe to run more often
        // since generateDueInvoices() is idempotent per service+due_date.
        return 1440;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    public function handle(): void
    {
        // generateDueInvoices() already returns the IDs it created, so the
        // count is free — no extra bookkeeping inside the billing service.
        $this->stats = ['invoices_generated' => count($this->billing->generateDueInvoices())];
    }
}
