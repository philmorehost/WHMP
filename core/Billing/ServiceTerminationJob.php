<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;
use CodeVault\Cron\ReportsCronStats;
use CodeVault\Provisioning\ProvisioningService;
use DateTimeImmutable;
use Throwable;

/**
 * Terminates services that stayed unpaid past their product type's grace
 * window: VPS and dedicated servers reclaim after a day (the allocation costs
 * real money to hold), shared hosting after the longer window the admin sets.
 *
 * Runs hourly so a one-day server grace reclaims at the top of the hour once
 * the day has elapsed, rather than waiting for the next daily sweep.
 *
 * This is the one irreversible job in the platform — a terminated account and
 * its data are destroyed on the remote server. Three things guard it:
 *  1. It does nothing unless the admin explicitly enables auto-termination.
 *  2. Each service is re-checked against its own product type's grace, not
 *     the coarse window used to fetch candidates.
 *  3. A service whose invoice was paid in the meantime is excluded by the
 *     query, so paying at the last minute always wins the race.
 */
final class ServiceTerminationJob implements CronJob, ReportsCronStats
{
    /** @var array<string, int> counters for the daily activity report */
    private array $stats = [];

    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ServiceLifecycleSettings $policy,
        private readonly ProvisioningService $provisioning
    ) {
    }

    public function name(): string
    {
        return 'service-termination';
    }

    public function frequencyMinutes(): int
    {
        return 60;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    public function handle(): void
    {
        $this->stats = ['services_terminated' => 0];

        if (!$this->policy->autoTerminateEnabled()) {
            return;
        }

        // Fetch against the shorter of the two windows, then apply each
        // service's own grace below. Fetching per type would need one query
        // per type and would miss any type added later.
        $shortestGrace = min(
            $this->policy->serverTerminationGraceDays(),
            $this->policy->generalTerminationGraceDays()
        );

        $today = new DateTimeImmutable('today');

        foreach ($this->services->expiredForTermination($shortestGrace) as $service) {
            $grace = $this->policy->terminationGraceDaysFor($service['product_type'] ?? null);

            if (!$this->isPastGrace($service['next_due_date'] ?? null, $grace, $today)) {
                continue;
            }

            if ($this->terminateOne($service)) {
                $this->stats['services_terminated']++;
            }
        }
    }

    /**
     * Whole days elapsed since the due date, compared against this service's
     * grace. Date-only arithmetic (both sides normalised to midnight) so a
     * service expiring at 23:00 isn't treated as a day older than one
     * expiring at 01:00 on the same date.
     */
    private function isPastGrace(?string $dueDate, int $graceDays, DateTimeImmutable $today): bool
    {
        if ($dueDate === null || trim($dueDate) === '') {
            return false;
        }

        $due = DateTimeImmutable::createFromFormat('Y-m-d', substr($dueDate, 0, 10));

        if ($due === false) {
            return false;
        }

        $elapsedDays = (int) $due->setTime(0, 0)->diff($today)->format('%r%a');

        return $elapsedDays > $graceDays;
    }

    /** @param array<string, mixed> $service */
    private function terminateOne(array $service): bool
    {
        $serviceId = (int) $service['id'];

        // Never provisioned remotely — there is no account to destroy, so
        // closing the local record is the whole of the termination.
        if ($service['server_id'] === null) {
            $this->services->terminate($serviceId);

            return true;
        }

        try {
            $result = $this->provisioning->terminate($serviceId);
        } catch (Throwable) {
            return false;
        }

        // Only count it when the module confirms. A failed call leaves the
        // service untouched and retries next hour — far better than recording
        // a termination that never happened on the server.
        return ($result['success'] ?? false) === true;
    }
}
