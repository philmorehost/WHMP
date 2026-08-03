<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;
use CodeVault\Cron\ReportsCronStats;
use DateTimeImmutable;

/**
 * Deletes services that have sat in 'terminated' status past the admin's
 * configured window — keeps the services table from accumulating rows for
 * accounts that were reclaimed months or years ago and will never be
 * referenced again. Off by default, same safety shape as ServiceTerminationJob:
 * an admin has to explicitly opt in before anything is permanently deleted.
 *
 * Deleting the row is safe for billing history: invoices.service_id is
 * ON DELETE SET NULL (migration 0034), so every invoice/line item this
 * service was ever billed on survives untouched — only the service record
 * itself, which has nothing left to do once terminated, is removed.
 */
final class ServicePruningJob implements CronJob, ReportsCronStats
{
    /** @var array<string, int> counters for the daily activity report */
    private array $stats = [];

    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ServiceLifecycleSettings $policy
    ) {
    }

    public function name(): string
    {
        return 'service-pruning';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    public function handle(): void
    {
        $this->stats = ['services_pruned' => 0];

        if (!$this->policy->pruneTerminatedEnabled()) {
            return;
        }

        $cutoff = (new DateTimeImmutable('-' . $this->policy->pruneTerminatedDays() . ' days'))->format('Y-m-d H:i:s');
        $candidates = $this->services->terminatedBefore($cutoff);

        if ($candidates === []) {
            return;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $candidates);
        $this->stats['services_pruned'] = $this->services->bulkDelete($ids);
    }
}
