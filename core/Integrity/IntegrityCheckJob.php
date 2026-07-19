<?php

declare(strict_types=1);

namespace CodeVault\Integrity;

use CodeVault\Cron\CronJob;

/**
 * Periodically refreshes the cached activation status so request-time
 * checks never block on a network call — IntegrityManager::check() itself
 * already no-ops within the 6h cache window, this just keeps that cache warm.
 */
final class IntegrityCheckJob implements CronJob
{
    public function __construct(
        private readonly IntegrityManager $integrity
    ) {
    }

    public function name(): string
    {
        return 'system.integrity-check';
    }

    public function frequencyMinutes(): int
    {
        return 360; // 6h, matches IntegrityManager::CACHE_TTL_SECONDS
    }

    public function handle(): void
    {
        $this->integrity->check();
    }
}
