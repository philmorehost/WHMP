<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Cron\CronJob;

/**
 * Periodic status/expiry reconciliation against each domain's registrar
 * (blueprint §4.4 "expiry+status sync") — catches expirations, registrar-side
 * renewals, and drift between CodeVault's cached state and the real record.
 */
final class DomainSyncJob implements CronJob
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly DomainService $domainService
    ) {
    }

    public function name(): string
    {
        return 'domain-sync';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        foreach ($this->domains->all('active') as $domain) {
            $this->domainService->sync((int) $domain['id']);
        }
    }
}
