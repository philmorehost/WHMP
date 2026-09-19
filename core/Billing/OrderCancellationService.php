<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use CodeVault\Domains\DomainRepository;
use CodeVault\Mail\EmailDispatcher;
use DateTimeImmutable;

/**
 * The one place an order's lifecycle is applied to everything the order
 * created.
 *
 * Cancelling an order — from the client portal or the admin Orders page —
 * used to leave the order's invoice and the services/domains it raised
 * disagreeing with it: the admin Cancel button didn't touch the invoice at
 * all, and neither path moved the services/domains, so a cancelled order
 * still showed a payable invoice and live services. This service owns that
 * cascade in both directions:
 *
 *   cancel     -> order cancelled, the order's unpaid invoice cancelled, and
 *                 every service/domain the order created set to `cancelled`;
 *   reactivate -> order back to pending, its invoice back to unpaid, and the
 *                 services/domains it cancelled set back to `pending` so the
 *                 order can be accepted (and provisioned) again — the client
 *                 can then pay the invoice, or an admin can mark it paid.
 *
 * Both entry points route through here so the two cannot drift; the client
 * path only adds an ownership check and the client/admin notifications.
 *
 * Status changes are local: nothing here calls a provisioning or registrar
 * module. Cancelling an order is an act on our own records — terminating a
 * live hosting account or transferring a domain away stays a separate,
 * deliberate operation (see CancellationRequestService).
 */
final class OrderCancellationService
{
    /** Service statuses an order cancellation is allowed to close. */
    private const CANCELLABLE_SERVICE_STATUSES = ['pending', 'active', 'suspended'];

    /** Domain statuses still in our hands (not already cancelled/transferred). */
    private const CANCELLABLE_DOMAIN_STATUSES = ['pending', 'active', 'expired', 'grace', 'redemption'];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly EmailDispatcher $mail,
        private readonly Database $db,
        private readonly InvoiceRepository $invoices,
        private readonly ServiceRepository $services,
        private readonly DomainRepository $domains
    ) {
    }

    /**
     * Client-side entry point: only the caller's own order, and the client
     * and every admin are notified.
     */
    public function clientCancelOrder(int $orderId, int $clientId, string $reason): bool
    {
        $order = $this->orders->findById($orderId);

        if ($order === null || (int) $order['client_id'] !== $clientId) {
            return false;
        }

        // A fulfilled order is not something a client withdraws. An admin can
        // still cancel one — they are the ones who can undo the consequences.
        if ((string) $order['status'] === 'completed') {
            return false;
        }

        if (!$this->cancelOrder($orderId, $reason)['success']) {
            return false;
        }

        $client = $this->db->selectOne('SELECT id, email, first_name FROM clients WHERE id = ?', [$clientId]);
        $this->notifyAdminOfCancellation($orderId, $client);
        $this->notifyClientOfCancellation($client, $orderId);

        return true;
    }

    /**
     * Cancel an order and everything it created. Callers: the admin Orders
     * page Cancel button and clientCancelOrder() above.
     *
     * @return array{success: bool, reason: string, invoiceId: int|null, invoiceCancelled: bool, services: int, domains: int}
     */
    public function cancelOrder(int $orderId, string $reason): array
    {
        $order = $this->orders->findById($orderId);

        if ($order === null) {
            return $this->outcome('order-not-found');
        }

        if ((string) $order['status'] === 'cancelled') {
            return $this->outcome('already-cancelled');
        }

        // Mark the order cancelled (the canonical status the admin Orders page
        // filters on) alongside the is_cancelled audit flag.
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update(
            'UPDATE orders SET status = ?, is_cancelled = 1, cancelled_at = ?, cancellation_reason = ?, updated_at = ? WHERE id = ?',
            ['cancelled', $now, $reason, $now, $orderId]
        );

        // The client must never be billed for an order they no longer have.
        // Only an unpaid invoice is touched: a paid one is money already
        // taken, which is the admin's to refund rather than ours to hide.
        $invoice = $this->invoices->findByOrder($orderId);
        $invoiceCancelled = false;

        if ($invoice !== null && (string) ($invoice['status'] ?? '') === 'unpaid') {
            $this->invoices->markCancelled((int) $invoice['id']);
            $invoiceCancelled = true;
        }

        return [
            'success' => true,
            'reason' => 'cancelled',
            'invoiceId' => $invoice !== null ? (int) $invoice['id'] : null,
            'invoiceCancelled' => $invoiceCancelled,
            'services' => $this->closeServices($orderId, $reason),
            'domains' => $this->closeDomains($orderId),
        ];
    }

    /**
     * Client-side reactivation — the same rules as the admin action, scoped
     * to the caller's own order.
     */
    public function clientReactivateOrder(int $orderId, int $clientId): bool
    {
        $order = $this->orders->findById($orderId);

        if ($order === null || (int) $order['client_id'] !== $clientId) {
            return false;
        }

        return $this->reactivateOrder($orderId)['success'];
    }

    /**
     * Undo a cancellation: the order goes back to pending, its invoice back
     * to unpaid, and the services/domains the cancellation closed back to
     * pending so acceptance can provision them again. The client can then pay
     * the invoice, or an admin can mark it paid.
     *
     * Only rows this order closed are restored — a service/domain that is
     * active, suspended or terminated is left exactly as it is.
     *
     * @return array{success: bool, reason: string, invoiceId: int|null, invoiceReactivated: bool, services: int, domains: int}
     */
    public function reactivateOrder(int $orderId): array
    {
        if ($this->orders->findById($orderId) === null) {
            return $this->reopenOutcome('order-not-found');
        }

        // Only a cancelled order is touched; the return value says whether
        // anything actually changed.
        if (!$this->orders->reactivate($orderId)) {
            return $this->reopenOutcome('not-cancelled');
        }

        $invoice = $this->invoices->findByOrder($orderId);
        $invoiceReactivated = $invoice !== null && $this->invoices->reactivate((int) $invoice['id']);

        return [
            'success' => true,
            'reason' => 'reactivated',
            'invoiceId' => $invoice !== null ? (int) $invoice['id'] : null,
            'invoiceReactivated' => $invoiceReactivated,
            'services' => $this->reopenServices($orderId),
            'domains' => $this->reopenDomains($orderId),
        ];
    }

    /** @return int number of services closed */
    private function closeServices(int $orderId, string $reason): int
    {
        $closed = 0;

        foreach ($this->services->forOrder($orderId) as $service) {
            if (!in_array((string) $service['status'], self::CANCELLABLE_SERVICE_STATUSES, true)) {
                continue;
            }

            $this->services->updateStatus(
                (int) $service['id'],
                'cancelled',
                "Order #{$orderId} cancelled: {$reason}"
            );
            $closed++;
        }

        return $closed;
    }

    /** @return int number of domains closed */
    private function closeDomains(int $orderId): int
    {
        $closed = 0;

        foreach ($this->domains->forOrder($orderId) as $domain) {
            if (!in_array((string) $domain['status'], self::CANCELLABLE_DOMAIN_STATUSES, true)) {
                continue;
            }

            $this->domains->setStatus((int) $domain['id'], 'cancelled');
            $closed++;
        }

        return $closed;
    }

    /**
     * Back to `pending`, matching the order returning to pending: these are
     * awaiting acceptance again. AcceptOrderJob is responsible for not
     * re-creating anything that already exists on the remote server — see the
     * already-provisioned guard there.
     *
     * @return int number of services reopened
     */
    private function reopenServices(int $orderId): int
    {
        $reopened = 0;

        foreach ($this->services->forOrder($orderId) as $service) {
            if ((string) $service['status'] !== 'cancelled') {
                continue;
            }

            $this->services->updateStatus((int) $service['id'], 'pending');
            $reopened++;
        }

        return $reopened;
    }

    /** @return int number of domains reopened */
    private function reopenDomains(int $orderId): int
    {
        $reopened = 0;

        foreach ($this->domains->forOrder($orderId) as $domain) {
            if ((string) $domain['status'] !== 'cancelled') {
                continue;
            }

            $this->domains->setStatus((int) $domain['id'], 'pending');
            $reopened++;
        }

        return $reopened;
    }

    /**
     * @return array{success: bool, reason: string, invoiceId: int|null, invoiceCancelled: bool, services: int, domains: int}
     */
    private function outcome(string $reason): array
    {
        return [
            'success' => false,
            'reason' => $reason,
            'invoiceId' => null,
            'invoiceCancelled' => false,
            'services' => 0,
            'domains' => 0,
        ];
    }

    /**
     * @return array{success: bool, reason: string, invoiceId: int|null, invoiceReactivated: bool, services: int, domains: int}
     */
    private function reopenOutcome(string $reason): array
    {
        return [
            'success' => false,
            'reason' => $reason,
            'invoiceId' => null,
            'invoiceReactivated' => false,
            'services' => 0,
            'domains' => 0,
        ];
    }

    // EmailDispatcher has no send() method — the raw-content entry point is
    // sendRaw($subject, $html, $to, $clientId), a different name and argument
    // order, so both notifications below were fatal on every call.
    private function notifyAdminOfCancellation(int $orderId, ?array $client): void
    {
        $name = $client['first_name'] ?? 'A client';
        $admins = $this->db->select('SELECT email FROM admins', []);
        foreach ($admins as $admin) {
            $this->mail->sendRaw(
                "Order #$orderId Cancelled by Client",
                htmlspecialchars("Client {$name} has cancelled order #$orderId. Please review in the admin dashboard.", ENT_QUOTES, 'UTF-8'),
                (string)$admin['email']
            );
        }
    }

    private function notifyClientOfCancellation(?array $client, int $orderId): void
    {
        if (!$client) return;
        $this->mail->sendRaw(
            "Order Cancellation Confirmed",
            htmlspecialchars("Your order #$orderId has been cancelled successfully.", ENT_QUOTES, 'UTF-8'),
            (string)$client['email'],
            isset($client['id']) ? (int)$client['id'] : null
        );
    }
}
