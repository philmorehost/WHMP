<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Settings\SettingsRepository;

/**
 * One place that answers "how long does an unpaid service get?".
 *
 * The suspension and termination jobs, the dunning job's late-fee step and the
 * admin settings screen all read the policy through here, so the rules can't
 * drift apart between the job that enforces them and the screen that displays
 * them.
 */
final class ServiceLifecycleSettings
{
    /**
     * Product types billed as dedicated capacity. These reclaim fast because
     * the hardware or hypervisor allocation stays reserved while the service
     * sits unpaid; shared hosting costs almost nothing to leave dormant.
     */
    private const SERVER_TYPES = ['vps', 'dedicated'];

    public function __construct(
        private readonly SettingsRepository $settings
    ) {
    }

    /** Days after the due date before a late fee is added to an overdue invoice. */
    public function lateFeeGraceDays(): int
    {
        return $this->days('billing.late_fee_grace_days', 0);
    }

    /**
     * Days past an invoice's due date before it is auto-cancelled.
     * 0 disables the sweep entirely.
     */
    public function autoCancelUnpaidDays(): int
    {
        return $this->days('billing.auto_cancel_unpaid_days', 0);
    }

    public function autoSuspendEnabled(): bool
    {
        return $this->settings->get('billing.auto_suspend_enabled', '0') === '1';
    }

    /** Days past the due date before an unpaid service is suspended. */
    public function suspensionGraceDays(): int
    {
        return $this->days('billing.suspension_grace_days', 7);
    }

    public function autoTerminateEnabled(): bool
    {
        return $this->settings->get('billing.auto_terminate_enabled', '0') === '1';
    }

    /**
     * Days past the due date before a service of this product type is
     * terminated. Unknown/empty types fall to the general grace, so a new
     * product type can never accidentally inherit the aggressive one-day
     * server window.
     */
    public function terminationGraceDaysFor(?string $productType): int
    {
        if ($productType !== null && in_array(strtolower(trim($productType)), self::SERVER_TYPES, true)) {
            return $this->days('billing.termination_grace_days_server', 1);
        }

        return $this->days('billing.termination_grace_days', 60);
    }

    public function serverTerminationGraceDays(): int
    {
        return $this->days('billing.termination_grace_days_server', 1);
    }

    public function pruneTerminatedEnabled(): bool
    {
        return $this->settings->get('billing.prune_terminated_enabled', '0') === '1';
    }

    /** Days a service may sit in 'terminated' status before its row is deleted outright. */
    public function pruneTerminatedDays(): int
    {
        return $this->days('billing.prune_terminated_days', 90);
    }

    public function generalTerminationGraceDays(): int
    {
        return $this->days('billing.termination_grace_days', 60);
    }

    /** @return array<int, string> */
    public function serverTypes(): array
    {
        return self::SERVER_TYPES;
    }

    private function days(string $key, int $default): int
    {
        $value = $this->settings->get($key, (string) $default);

        // A blank or non-numeric setting means "unset", not "zero days" —
        // reading it as 0 would terminate everything the moment it expired.
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return $default;
        }

        return max(0, (int) $value);
    }
}
