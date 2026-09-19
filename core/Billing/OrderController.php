<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Domains\DomainRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Queue\QueueInterface;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Support\App;
use CodeVault\View;

/**
 * Orders + the Pending queue (blueprint §4.3). Accepting an order marks it
 * active and defers fulfillment — provisioning each service (§4.4) and
 * registering each domain — to a background AcceptOrderJob, so the admin's
 * Accept Order click returns immediately instead of blocking on registrar /
 * module API calls that used to time out and crash the request. A module
 * failure doesn't block acceptance; the service stays `pending` with a
 * recorded error the admin can see and retry.
 */
final class OrderController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly OrderRepository $orders,
        private readonly ServiceRepository $services,
        private readonly HookDispatcher $hooks,
        private readonly ActivityLogger $activity,
        private readonly OrderCancellationService $orderCancellation
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->query('status', '');
        $page = max(1, (int) $request->query('page', 1));

        // Column filters (Order ID / Client / Total / Status) — whitelisted
        // in OrderRepository::paginate(), preserved across pagination links.
        $filters = \CodeVault\Table\TableFilters::fromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['id' => true, 'client' => true, 'total' => true, 'status' => true]
        );

        $sort = \CodeVault\Table\TableFilters::sortFromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['id' => 'o.id', 'client' => 'c.last_name', 'total' => 'o.total', 'status' => 'o.status']
        );

        $results = $this->orders->paginate($status !== '' ? $status : null, $page, 15, $filters, $sort);

        return $this->render('billing.orders-index', [
            'results' => $results,
            'statusFilter' => $status,
            'filters' => $filters,
            'sort' => $sort,
            'filterColumns' => [
                ['filterable' => true, 'key' => 'id', 'label' => 'Order ID', 'type' => 'number', 'placeholder' => 'e.g. 13'],
                ['filterable' => true, 'key' => 'client', 'label' => 'Client', 'type' => 'text', 'placeholder' => 'Name or email'],
                ['filterable' => true, 'key' => 'total', 'label' => 'Total', 'type' => 'number', 'placeholder' => 'e.g. 19.99'],
                ['filterable' => true, 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => [
                    'pending' => 'Pending',
                    'active' => 'Active',
                    'cancelled' => 'Cancelled',
                    'fraud' => 'Fraud Review',
                ]],
                ['filterable' => false],
            ],
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $order = $this->orders->find((int) $params['id']);

        if ($order === null) {
            return Response::html('404 Not Found', 404);
        }

        // Per-product lookup for the order page's "Domain / Hostname" column:
        // each order item's product maps to the service checkout created for
        // it, whose `domain` (shared hosting / domain registrations) or
        // `hostname` (VPS / dedicated) is the detail worth showing. Kept as a
        // list per product and consumed in order by the view, so an order
        // with two lines of the same product shows each line's own domain
        // instead of both lines showing the last service created.
        $servicesByProduct = [];
        foreach ($this->services->forOrder((int) $order['id']) as $service) {
            $servicesByProduct[(int) $service['product_id']][] = $service;
        }

        // Domains the client bought on this order — standalone (riding the
        // hidden carrier product) or attached to a hosting line. Shown
        // explicitly on the order page so the admin can see exactly what will
        // be registered when the order is accepted; otherwise a pending
        // domain is only implied by a column on an item row.
        $domains = App::container()->make(DomainRepository::class)->forOrder((int) $order['id']);

        return $this->render('billing.order-show', [
            'order' => $order,
            'items' => $this->orders->items((int) $order['id']),
            'servicesByProduct' => $servicesByProduct,
            'domains' => $domains,
            'msg' => $request->query('msg') !== null ? (string) $request->query('msg') : null,
        ]);
    }

    public function accept(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $order = $this->orders->find($id);

        if ($order === null) {
            return Response::redirect('/admin/orders');
        }

        if ($order['status'] === 'fraud') {
            $this->orders->stampFraudReviewer($id, (int) $this->guard->currentAdmin()['id']);
        }

        // Mark the order active up front, then hand every slow step —
        // provisioning calls and domain registrations — to a background job.
        // The admin gets an immediate confirmation and a completion/failure
        // email once the worker finishes. With the SyncQueue fallback (no
        // Redis / tests) the job still runs inline, exactly like before.
        $this->orders->accept($id);

        App::container()
            ->make(QueueInterface::class)
            ->push(new AcceptOrderJob($id, (int) $this->guard->currentAdmin()['id'], $request->ip()));

        return Response::redirect("/admin/orders/{$id}?msg=" . urlencode('Order accepted — provisioning is running in the background.'));
    }

    public function cancel(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $order = $this->orders->find($id);

        if ($order !== null && $order['status'] === 'fraud') {
            $this->orders->stampFraudReviewer($id, (int) $this->guard->currentAdmin()['id']);
        }

        $reason = trim((string) $request->input('reason', ''));

        if ($reason === '') {
            $reason = 'Cancelled by admin';
        }

        // One cascade: the order, its unpaid invoice, and every
        // service/domain the order created (OrderCancellationService). The
        // admin used to cancel only the order, leaving a payable invoice and
        // live services behind on a cancelled order.
        $result = $this->orderCancellation->cancelOrder($id, $reason);

        if (!$result['success']) {
            $message = match ($result['reason']) {
                'order-not-found' => 'That order could not be found.',
                'already-cancelled' => 'That order is already cancelled.',
                default => 'That order could not be cancelled.',
            };

            return Response::redirect("/admin/orders/{$id}?msg=" . urlencode($message));
        }

        $this->hooks->fire(HookPoints::ORDER_CANCELLED, ['orderId' => $id]);
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'order.cancelled', 'order', $id, "Cancelled order #{$id}", $request->ip());

        return Response::redirect("/admin/orders/{$id}?msg=" . urlencode($this->cancellationSummary($result)));
    }

    /**
     * Puts a cancelled order back to pending and, in the same cascade,
     * restores its invoice to unpaid and the services/domains the
     * cancellation closed back to pending — so the order is whole again and
     * the client can pay the invoice, or an admin can mark it paid.
     *
     * Everything runs through OrderCancellationService::reactivateOrder(),
     * the exact inverse of the cancel path, so the two can't drift.
     */
    public function reactivate(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];

        if ($this->orders->find($id) === null) {
            return Response::html('404 Not Found', 404);
        }

        $result = $this->orderCancellation->reactivateOrder($id);

        if (!$result['success']) {
            return Response::redirect("/admin/orders/{$id}?msg=" . urlencode('Only a cancelled order can be reactivated.'));
        }

        $adminId = (int) $this->guard->currentAdmin()['id'];
        $this->activity->log('admin', $adminId, 'order.reactivated', 'order', $id, "Reactivated cancelled order #{$id} (set back to pending)", $request->ip());

        if ($result['invoiceReactivated'] && $result['invoiceId'] !== null) {
            $this->activity->log('admin', $adminId, 'invoice.status_changed', 'invoice', $result['invoiceId'], "Reactivated invoice #{$result['invoiceId']} with order #{$id}", $request->ip());
        }

        return Response::redirect("/admin/orders/{$id}?msg=" . urlencode($this->reactivationSummary($result)));
    }

    /**
     * Human-readable outcome of a cancellation, including what happened to
     * the invoice and how many services/domains were closed with it.
     *
     * @param array{invoiceId: int|null, invoiceCancelled: bool, services: int, domains: int} $result
     */
    private function cancellationSummary(array $result): string
    {
        $parts = ['Order cancelled.'];

        if ($result['invoiceCancelled'] && $result['invoiceId'] !== null) {
            $parts[] = 'Its unpaid invoice INV-' . $result['invoiceId'] . ' was cancelled with it.';
        } elseif ($result['invoiceId'] !== null) {
            $parts[] = 'Invoice INV-' . $result['invoiceId'] . ' was left as it is (it is not unpaid).';
        }

        ($result['services'] > 0) && $parts[] = $result['services'] . ' service(s) set to cancelled.';
        ($result['domains'] > 0) && $parts[] = $result['domains'] . ' domain(s) set to cancelled.';

        return implode(' ', $parts);
    }

    /**
     * @param array{invoiceId: int|null, invoiceReactivated: bool, services: int, domains: int} $result
     */
    private function reactivationSummary(array $result): string
    {
        $parts = ['Order reactivated — it is pending again.'];

        if ($result['invoiceReactivated'] && $result['invoiceId'] !== null) {
            $parts[] = 'Its invoice INV-' . $result['invoiceId'] . ' was set back to unpaid.';
        } elseif ($result['invoiceId'] !== null) {
            $parts[] = 'Its invoice INV-' . $result['invoiceId'] . ' was already unpaid.';
        }

        ($result['services'] > 0) && $parts[] = $result['services'] . ' service(s) set back to pending — accept the order again to provision them.';
        ($result['domains'] > 0) && $parts[] = $result['domains'] . ' domain(s) set back to pending.';

        return implode(' ', $parts);
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'order.deleted', 'order', $id, "Deleted order #{$id}", $request->ip());
        $this->orders->delete($id);

        return Response::redirect('/admin/orders');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::ORDERS_MANAGE)) {
            return Response::html('403 Forbidden — missing orders.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Orders',
            'content' => $content,
        ]));
    }
}
