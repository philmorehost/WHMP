<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Orders + the Pending queue (blueprint §4.3). Accepting an order triggers
 * provisioning (§4.4) for each service it created — "accepted" no longer
 * just means "legitimate order", it's the point fulfillment actually
 * starts. A module failure doesn't block acceptance; the service stays
 * `pending` with a recorded error the admin can see and retry.
 */
final class OrderController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly OrderRepository $orders,
        private readonly ServiceRepository $services,
        private readonly ProvisioningService $provisioning,
        private readonly ActivityLogger $activity,
        private readonly HookDispatcher $hooks
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->query('status', '');

        return $this->render('billing.orders-index', [
            'orders' => $this->orders->all($status !== '' ? $status : null),
            'statusFilter' => $status,
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

        return $this->render('billing.order-show', [
            'order' => $order,
            'items' => $this->orders->items((int) $order['id']),
        ]);
    }

    public function accept(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $order = $this->orders->find($id);

        if ($order !== null && $order['status'] === 'fraud') {
            $this->orders->stampFraudReviewer($id, (int) $this->guard->currentAdmin()['id']);
        }

        $this->orders->accept($id);

        foreach ($this->services->forOrder($id) as $service) {
            // Only provision services still awaiting it — accept can be
            // called again (retry, double submit) without re-running
            // create() against an already-active or cancelled service.
            if ($service['status'] !== 'pending') {
                continue;
            }

            $result = $this->provisioning->provision((int) $service['id']);

            if (!$result['success']) {
                $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'service.provisioning_failed', 'service', (int) $service['id'], "Provisioning failed: {$result['message']}", $request->ip());
            }
        }

        $this->hooks->fire(HookPoints::ORDER_ACCEPTED, ['orderId' => $id]);
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'order.accepted', 'order', $id, "Accepted order #{$id}", $request->ip());

        return Response::redirect("/admin/orders/{$id}");
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
