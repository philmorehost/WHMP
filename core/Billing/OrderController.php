<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
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
        private readonly ActivityLogger $activity
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
        // `hostname` (VPS / dedicated) is the detail worth showing.
        $servicesByProduct = [];
        foreach ($this->services->forOrder((int) $order['id']) as $service) {
            $servicesByProduct[(int) $service['product_id']] = $service;
        }

        return $this->render('billing.order-show', [
            'order' => $order,
            'items' => $this->orders->items((int) $order['id']),
            'servicesByProduct' => $servicesByProduct,
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

        $this->orders->cancel($id);
        $this->hooks->fire(HookPoints::ORDER_CANCELLED, ['orderId' => $id]);
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'order.cancelled', 'order', $id, "Cancelled order #{$id}", $request->ip());

        return Response::redirect("/admin/orders/{$id}");
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
