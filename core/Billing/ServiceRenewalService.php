<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Provisioning\ProvisioningService;
use Throwable;

/**
 * What happens to a service when its renewal invoice is paid: the due date
 * rolls forward one billing cycle, and a service suspended for non-payment
 * comes back to life.
 *
 * Split out of the InvoicePaid listener so the behaviour is testable and so
 * an admin-triggered reactivation can reuse exactly the same path.
 */
final class ServiceRenewalService
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ProvisioningService $provisioning
    ) {
    }

    /**
     * @return array{renewed: bool, unsuspended: bool, reason?: string}
     */
    public function renewPaidService(int $serviceId): array
    {
        $service = $this->services->findById($serviceId);

        if ($service === null) {
            return ['renewed' => false, 'unsuspended' => false, 'reason' => 'service-not-found'];
        }

        $status = (string) ($service['status'] ?? '');

        // A terminated or cancelled service isn't brought back by a payment —
        // the account is gone on the remote server, so silently "reactivating"
        // it locally would show the client a service that no longer exists.
        if (in_array($status, ['terminated', 'cancelled'], true)) {
            return ['renewed' => false, 'unsuspended' => false, 'reason' => 'service-closed'];
        }

        $renewed = $this->advanceDueDate($service);
        $unsuspended = false;

        if ($status === 'suspended') {
            $unsuspended = $this->unsuspend($service);
        }

        return ['renewed' => $renewed, 'unsuspended' => $unsuspended];
    }

    /** @param array<string, mixed> $service */
    private function advanceDueDate(array $service): bool
    {
        $currentDue = (string) ($service['next_due_date'] ?? '');
        $cycle = (string) ($service['billing_cycle'] ?? '');

        if ($currentDue === '' || $cycle === '' || $cycle === 'one_time') {
            return false;
        }

        // Advance from the existing due date, not from today, so a client who
        // pays late doesn't quietly gain the days they were overdue.
        $next = ServiceRepository::nextCycleDate($currentDue, $cycle);

        $this->services->advanceNextDueDate((int) $service['id'], $next);

        return true;
    }

    /** @param array<string, mixed> $service */
    private function unsuspend(array $service): bool
    {
        $serviceId = (int) $service['id'];

        // Nothing remote was ever provisioned — clearing the local status is
        // the whole of the unsuspension.
        if (($service['server_id'] ?? null) === null) {
            $this->services->unsuspend($serviceId);

            return true;
        }

        try {
            $result = $this->provisioning->unsuspend($serviceId);
        } catch (Throwable) {
            return false;
        }

        return ($result['success'] ?? false) === true;
    }
}
