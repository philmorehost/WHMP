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
    /**
     * Whether a suspended service is on hold by the customer's CURRENT store (migration
     * 0210), so a payment must not lift it. A mark naming any other store is stale.
     *
     * @param array<string, mixed> $service a row from ServiceRepository::find()
     */
    public static function isHeldByStore(array $service): bool
    {
        $mark = (int) ($service['suspended_by_reseller_id'] ?? 0);

        return $mark > 0 && $mark === (int) ($service['client_reseller_id'] ?? 0);
    }

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

        // A suspension the customer's STORE made is the store's decision to undo,
        // not the payment's — the store may have suspended for abuse, or for
        // something the customer owes it outside this invoice. Payment still renews
        // the service; the store lifts the suspension from its customer page.
        //
        // Only while the customer is still that store's: after a move to another
        // store or to the platform (ClientMigrationService, which deliberately never
        // rewrites service rows), the old store's mark is stale, nobody it names can
        // act on it any more, and the suspension is treated as an ordinary one.
        if ($status === 'suspended' && !self::isHeldByStore($service)) {
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
