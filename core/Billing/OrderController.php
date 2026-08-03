<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServiceDetailsNotifier;
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
        private readonly HookDispatcher $hooks,
        private readonly ServiceDetailsNotifier $serviceDetails
    ) {
    }

    /**
     * Whether this service's product is provisioned by hand rather than
     * through a module — products whose autosetup is "off".
     *
     * @param array<string, mixed> $service
     */
    private function isManualSetup(array $service): bool
    {
        $product = \CodeVault\Support\App::container()
            ->make(\CodeVault\Catalog\ProductRepository::class)
            ->find((int) $service['product_id']);

        return ($product['autosetup'] ?? 'payment') === 'off';
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

            // Respect the product's own setup mode.
            //
            // "off" means the admin provisions this product by hand — the usual
            // choice for dedicated servers and any VPS ordered outside an API.
            // Checkout already honoured it; acceptance did not, so approving an
            // order still fired a create() at the provider and surfaced errors
            // like "Nocix does not support ordering dedicated servers via API"
            // for products that were never meant to be automated.
            //
            // The service simply stays pending until the admin sets it live
            // from the service page.
            if ($this->isManualSetup($service)) {
                $this->activity->log(
                    'admin',
                    (int) $this->guard->currentAdmin()['id'],
                    'service.manual_setup_required',
                    'service',
                    (int) $service['id'],
                    "Order accepted; service #{$service['id']} is set to manual setup and was not sent to a provisioning module.",
                    $request->ip()
                );

                continue;
            }

            $result = $this->provisioning->provision((int) $service['id']);

            if (!$result['success']) {
                $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'service.provisioning_failed', 'service', (int) $service['id'], "Provisioning failed: {$result['message']}", $request->ip());
            }

            // Tell the client how to get in. Read after provisioning, not
            // before — a module that just created the account will have
            // written the username/password we are about to send.
            //
            // Silent when there is nothing to send yet: manual provisioning
            // fills the details in after approval, and the admin sends it from
            // the service page then. Never fatal — a mail failure must not
            // leave the order half-accepted.
            try {
                $notified = $this->serviceDetails->sendForService((int) $service['id']);

                if ($notified['sent']) {
                    $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'service.details_emailed', 'service', (int) $service['id'], $notified['reason'], $request->ip());
                }
            } catch (\Throwable $e) {
                $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'service.details_email_failed', 'service', (int) $service['id'], 'Could not email service details: ' . $e->getMessage(), $request->ip());
            }
        }

        $container = \CodeVault\Support\App::container();
        $domainRepo = $container->make(\CodeVault\Domains\DomainRepository::class);
        $domainService = $container->make(\CodeVault\Domains\DomainService::class);
        $domainPricing = $container->make(\CodeVault\Domains\DomainPricingRepository::class);

        foreach ($domainRepo->forOrder($id) as $domain) {
            if ($domain['status'] !== 'pending') {
                continue;
            }

            // Same rule as services: a TLD set to manual registration is not
            // sent to a registrar on acceptance.
            $tldPricing = $domainPricing->findByTld((string) $domain['tld']);

            if (($tldPricing['autosetup_registration'] ?? 'payment') === 'off') {
                $this->activity->log(
                    'admin',
                    (int) $this->guard->currentAdmin()['id'],
                    'domain.manual_setup_required',
                    'domain',
                    (int) $domain['id'],
                    "Order accepted; domain #{$domain['id']} is set to manual registration and was not sent to a registrar.",
                    $request->ip()
                );

                continue;
            }

            $result = $domainService->register((int) $domain['id']);

            if (!$result['success']) {
                $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'domain.registration_failed', 'domain', (int) $domain['id'], "Domain registration failed: {$result['message']}", $request->ip());
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
