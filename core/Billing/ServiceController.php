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
use CodeVault\Provisioning\ServiceDetailsNotifier;

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
        private readonly ServerRepository $servers,
        private readonly ServiceDetailsNotifier $serviceDetails
    ) {
    }

    /**
     * Whether this service's product is one addressed by hostname rather than
     * by domain name — a VPS or a dedicated server.
     *
     * Same product-type test the storefront already uses to decide whether to
     * ask for a hostname at checkout (resources/views/cart/product.php), so
     * the admin form and the order form agree on what a product needs.
     */
    private function hidesDomainField(int $productId): bool
    {
        $product = $this->products->find($productId);

        return in_array($product['type'] ?? '', ['vps', 'dedicated'], true);
    }

    /**
     * Emails the client this service's access details on demand.
     *
     * Order acceptance already tries this automatically, but manual
     * provisioning runs the other way round — approve first, fill the
     * hostname/IP/password in afterwards — so the auto-send has nothing to
     * say and skips. This is the admin's "now send it" once the details exist,
     * and doubles as a resend when a client loses the email.
     */
    public function sendDetails(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];

        if ($this->services->find($id) === null) {
            return Response::html('404 Not Found', 404);
        }

        try {
            $result = $this->serviceDetails->sendForService($id);
        } catch (\Throwable $e) {
            return Response::redirect("/admin/services/{$id}?details_error=" . urlencode('Could not send: ' . $e->getMessage()));
        }

        if (!$result['sent']) {
            return Response::redirect("/admin/services/{$id}?details_error=" . urlencode($result['reason']));
        }

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'service.details_emailed',
            'service',
            $id,
            $result['reason'],
            $request->ip()
        );

        return Response::redirect("/admin/services/{$id}?details_sent=1");
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->query('status', '');
        $page = (int) $request->query('page', 1);
        $search = trim((string) $request->query('q', ''));

        return $this->render('billing.services-index', [
            'results' => $this->services->paginate($status !== '' ? $status : null, $page, 20, $search),
            'statusFilter' => $status,
            'search' => $search,
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
            // A VPS/dedicated box is identified by hostname, so the domain
            // field is noise on those products.
            'showDomainField' => !in_array($product['type'] ?? '', ['vps', 'dedicated'], true),
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
            'hostname' => trim((string) $request->input('hostname', '')) ?: null,
            'dedicated_ip' => trim((string) $request->input('dedicated_ip', '')) ?: null,
            'assigned_ips' => trim((string) $request->input('assigned_ips', '')) ?: null,
            'server_id' => $serverId !== null && $serverId !== '' ? (int) $serverId : null,
        ];

        // A VPS or dedicated server is identified by its hostname; the domain
        // field is meaningless there and the form doesn't render it. Sending
        // the key anyway would null the column purely because the input was
        // absent, so it is omitted for those types — updateDetails() leaves
        // out-of-scope columns alone rather than clearing them.
        if (!$this->hidesDomainField((int) $service['product_id'])) {
            $fields['domain'] = trim((string) $request->input('domain', '')) ?: null;
        }

        // The password input renders masked and empty, so a blank submission
        // is "I didn't touch it", never "wipe the stored password". Only pass
        // the key through when the admin actually typed something.
        $password = trim((string) $request->input('password', ''));

        if ($password !== '') {
            $fields['password'] = $password;
        }

        $this->services->updateDetails($id, $fields);

        // "Set to package price" backs the service's recurring amount onto the
        // product's current catalog price for this billing cycle — the admin
        // equivalent of "WHMCS: update pricing" for a single service. It is a
        // plain write; no order, proration or upgrade logic runs.
        if ($request->input('set_package_price') !== null) {
            $priceRow = $this->pricing->find((int) $service['product_id'], (string) $service['billing_cycle']);

            if ($priceRow === null) {
                return Response::redirect("/admin/services/{$id}?price_error=" . urlencode('This product has no price for the service\'s billing cycle.'));
            }

            $this->services->updateAmount($id, (float) $priceRow['price']);

            $this->activity->log(
                'admin',
                (int) $this->guard->currentAdmin()['id'],
                'service.price_set_to_package',
                'service',
                $id,
                "Set service #{$id} recurring price to package price \$" . rtrim(rtrim(sprintf('%.2f', (float) $priceRow['price']), '0'), '.'),
                $request->ip()
            );
        }

        $admin = $this->guard->currentAdmin();
        $this->activity->log(
            'admin',
            (int) $admin['id'],
            'service.edit_details',
            'service',
            $id,
            'Admin updated client service #' . $id . ' details (' . implode(', ', array_keys($fields)) . ')',
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
     * Sets a service's status directly, with no call to any provisioning
     * module or provider API.
     *
     * This is what makes manual provisioning usable. A dedicated server is
     * ordered through the provider's own portal — Nocix has no ordering
     * endpoint at all — so after the admin has built the box by hand there has
     * to be a way to say "this is live now" and have the client see it in their
     * service list. Every other status action here (suspend, unsuspend,
     * terminate) goes through the module and fails on a manually-managed
     * product.
     *
     * Deliberately does NOT touch the server: flipping a status locally must
     * never suspend or terminate a real machine as a side effect.
     */
    public function setStatus(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $service = $this->services->find($id);

        if ($service === null) {
            return Response::html('404 Not Found', 404);
        }

        $allowed = ['pending', 'active', 'suspended', 'cancelled', 'terminated'];
        $status = strtolower(trim((string) $request->input('status', '')));

        if (!in_array($status, $allowed, true)) {
            return Response::redirect("/admin/services/{$id}?status_error=" . urlencode('Choose a status to apply.'));
        }

        $previous = (string) $service['status'];

        if ($status === $previous) {
            return Response::redirect("/admin/services/{$id}");
        }

        $this->services->updateStatus($id, $status);

        // Clear any stale provisioning error once the service is live — it
        // would otherwise keep showing the module failure that manual setup
        // just made irrelevant.
        if ($status === 'active') {
            $this->services->recordProvisioningError($id, null);
        }

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'service.status_set_manually',
            'service',
            $id,
            "Status changed manually from {$previous} to {$status} for service #{$id} (no provider API call).",
            $request->ip()
        );

        // Activating is the moment the client can actually use the service, so
        // it is the natural point to send them their access details — the
        // manual-provisioning equivalent of what order acceptance does for an
        // automated product.
        //
        // Only on the transition INTO active, so re-saving the same status
        // doesn't re-send. Silent when nothing is filled in yet, and never
        // fatal: a mail failure must not undo a status change that already
        // succeeded.
        $notice = '';

        if ($status === 'active' && $previous !== 'active') {
            try {
                $notified = $this->serviceDetails->sendForService($id);
                $notice = '&details=' . rawurlencode($notified['sent'] ? 'sent' : 'skipped');

                $this->activity->log(
                    'admin',
                    (int) $this->guard->currentAdmin()['id'],
                    $notified['sent'] ? 'service.details_emailed' : 'service.details_email_skipped',
                    'service',
                    $id,
                    $notified['reason'],
                    $request->ip()
                );
            } catch (\Throwable $e) {
                $notice = '&details=' . rawurlencode('failed');
                $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'service.details_email_failed', 'service', $id, 'Could not email service details: ' . $e->getMessage(), $request->ip());
            }
        }

        return Response::redirect("/admin/services/{$id}?status_set=" . urlencode($status) . $notice);
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

    public function delete(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $service = $this->services->find($id);

        if ($service !== null) {
            $this->services->delete($id);
            $admin = $this->guard->currentAdmin();
            $adminId = $admin ? (int) $admin['id'] : null;
            $this->activity->log('admin', $adminId, 'service.delete', 'service', $id, "Deleted service #{$id} ({$service['product_name']})");
        }

        return Response::redirect('/admin/services?msg=' . urlencode('Service deleted successfully.'));
    }

    public function bulkDelete(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ids = array_filter(
            array_map('intval', (array) $request->input('service_ids', [])),
            fn($id) => $id > 0
        );

        if (empty($ids)) {
            return Response::redirect('/admin/services?msg=' . urlencode('No services were selected for deletion.'));
        }

        $deletedCount = $this->services->bulkDelete($ids);
        $admin = $this->guard->currentAdmin();
        $adminId = $admin ? (int) $admin['id'] : null;
        $this->activity->log('admin', $adminId, 'service.bulk_delete', 'service', null, "Bulk deleted {$deletedCount} service(s).");

        return Response::redirect('/admin/services?msg=' . urlencode("Successfully deleted {$deletedCount} service(s)."));
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
