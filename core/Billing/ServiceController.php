<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Catalog\BillingCycle;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use CodeVault\Provisioning\ServerRepository;

final class ServiceController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ServiceRepository $services,
        private readonly ProductRepository $products,
        private readonly ProductPricingRepository $pricing,
        private readonly ProrationService $proration,
        private readonly ProvisioningService $provisioning,
        private readonly ActivityLogger $activity,
        private readonly ServerRepository $servers
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->query('status', '');
        $page = (int) $request->query('page', 1);

        return $this->render('billing.services-index', [
            'results' => $this->services->paginate($status !== '' ? $status : null, $page),
            'statusFilter' => $status,
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $service = $this->services->find((int) $params['id']);

        if ($service === null) {
            return Response::html('404 Not Found', 404);
        }

        $product = $this->products->find((int) $service['product_id']);
        $allServers = $this->servers->all();

        $servers = $allServers;
        if ($product !== null && $product['server_group_id'] !== null) {
            $groupId = (int) $product['server_group_id'];
            $servers = array_filter($allServers, fn ($srv) => (int) ($srv['server_group_id'] ?? 0) === $groupId);
            $servers = array_values($servers);
        }

        return $this->render('billing.service-show', [
            'service' => $service,
            'products' => $this->products->all(includeHidden: false),
            'cycles' => BillingCycle::labels(),
            'modes' => ProrationMode::labels(),
            'servers' => $servers,
        ]);
    }

    public function updateDetails(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $service = $this->services->find($id);

        if ($service === null) {
            return Response::html('404 Not Found', 404);
        }

        $serverId = $request->input('server_id');

        $fields = [
            'username' => trim((string) $request->input('username', '')) ?: null,
            'domain' => trim((string) $request->input('domain', '')) ?: null,
            'hostname' => trim((string) $request->input('hostname', '')) ?: null,
            'server_id' => $serverId !== null && $serverId !== '' ? (int) $serverId : null,
        ];

        $this->services->updateDetails($id, $fields);

        $admin = $this->guard->currentAdmin();
        $this->activity->log(
            'admin',
            (int) $admin['id'],
            'service.edit_details',
            'service',
            $id,
            "Admin updated client service #{$id} details (username, domain, hostname, server_id)",
            $request->ip()
        );

        return Response::redirect("/admin/services/{$id}");
    }

    public function upgrade(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $newProductId = (int) $request->input('product_id', 0);
        $mode = (string) $request->input('proration_mode', ProrationMode::NONE);
        $service = $this->services->find($id);

        $product = $this->products->find($newProductId);
        $priceRow = $product !== null ? $this->pricing->find($newProductId, $service['billing_cycle']) : null;

        if ($product === null || $priceRow === null) {
            return $this->render('billing.service-show', [
                'service' => $service,
                'products' => $this->products->all(includeHidden: false),
                'cycles' => BillingCycle::labels(),
                'modes' => ProrationMode::labels(),
                'error' => 'Selected product has no pricing for this service\'s billing cycle.',
            ]);
        }

        $result = $this->proration->upgrade($id, $newProductId, $product['name'], (float) $priceRow['price'], $mode);

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'service.upgraded',
            'service',
            $id,
            "Upgraded service #{$id} to \"{$product['name']}\" ({$mode}): charge \${$result['chargeAmount']}, credit \${$result['creditAmount']}",
            $request->ip()
        );

        return Response::redirect("/admin/services/{$id}");
    }

    public function suspend(Request $request, array $params): Response
    {
        return $this->transition($request, $params, fn (int $id) => $this->provisioning->suspend($id), 'service.suspended');
    }

    public function unsuspend(Request $request, array $params): Response
    {
        return $this->transition($request, $params, fn (int $id) => $this->provisioning->unsuspend($id), 'service.unsuspended');
    }

    public function terminate(Request $request, array $params): Response
    {
        return $this->transition($request, $params, fn (int $id) => $this->provisioning->terminate($id), 'service.terminated');
    }

    public function retryProvisioning(Request $request, array $params): Response
    {
        return $this->transition($request, $params, fn (int $id) => $this->provisioning->provision($id), 'service.provisioning_retried');
    }

    /**
     * @param callable(int): array{success: bool, message: string} $action
     */
    private function transition(Request $request, array $params, callable $action, string $logAction): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $result = $action($id);
        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            $logAction,
            'service',
            $id,
            $result['success'] ? "{$logAction} for service #{$id}" : "{$logAction} FAILED for service #{$id}: {$result['message']}",
            $request->ip()
        );

        return Response::redirect("/admin/services/{$id}");
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
            'title' => 'CodeVault Admin — Services',
            'content' => $content,
        ]));
    }
}
