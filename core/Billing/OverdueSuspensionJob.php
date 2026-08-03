<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;
use CodeVault\Cron\ReportsCronStats;
use CodeVault\Provisioning\ProvisioningService;
use Throwable;

/**
 * Suspends services whose renewal invoice has gone unpaid past the configured
 * grace period — the "locked until the renewal fee is paid" state.
 *
 * Suspension goes through ProvisioningService so the control panel actually
 * disables the account; flipping the local status alone would leave a
 * non-paying client with a fully working site. Paying the invoice lifts the
 * suspension again (see the InvoicePaid listener in Kernel), so unlike
 * termination this is reversible by design.
 *
 * Runs hourly and does nothing unless the admin has switched auto-suspend on.
 */
final class OverdueSuspensionJob implements CronJob, ReportsCronStats
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
        return 'overdue-suspension';
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
        $this->stats = ['overdue_suspensions' => 0];

        if (!$this->policy->autoSuspendEnabled()) {
            return;
        }

        foreach ($this->services->overdueForSuspension($this->policy->suspensionGraceDays()) as $service) {
            if ($this->suspendOne($service)) {
                $this->stats['overdue_suspensions']++;
            }
        }
    }

    /** @param array<string, mixed> $service */
    private function suspendOne(array $service): bool
    {
        $serviceId = (int) $service['id'];

        // Nothing was ever provisioned remotely (manual product, imported
        // record, provisioning never ran), so there's no panel account to
        // disable — locking it locally is the whole of the suspension.
        if ($service['server_id'] === null) {
            $this->services->suspend($serviceId);

            return true;
        }

        try {
            $result = $this->provisioning->suspend($serviceId);
        } catch (Throwable) {
            return false;
        }

        // transition() reports failure by return value, not exception, and
        // leaves the local status untouched when the module call fails. Leave
        // it that way and retry next hour rather than marking a service
        // suspended in the portal while the account is still live.
        return ($result['success'] ?? false) === true;
    }
}
