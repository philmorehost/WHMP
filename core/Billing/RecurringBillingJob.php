<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;

final class RecurringBillingJob implements CronJob
{
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

    public function handle(): void
    {
        $this->billing->generateDueInvoices();
    }
}
