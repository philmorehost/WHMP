<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Cron\CronJob;

final class DomainRenewalBillingJob implements CronJob
{
    public function __construct(
        private readonly DomainRenewalBillingService $billing
    ) {
    }

    public function name(): string
    {
        return 'domain-renewal-billing';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        $this->billing->generateDueInvoices();
    }
}
