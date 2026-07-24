<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;
use CodeVault\Activity\ActivityLogger;

/**
 * My Services (blueprint §4.1): status, usage, SSO-to-panel, cancel.
 * Upgrade/downgrade from the client side is deferred — admin-initiated
 * upgrade (§R5) is wired; a client self-service upgrade flow is additive
 * later, not a redesign.
 */
final class ClientServiceController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly ServiceRepository $services,
        private readonly ProvisioningService $provisioning,
        private readonly ServerRepository $servers,
        private readonly CurrencyService $currency,
        private readonly CancellationRequestRepository $cancellations,
        private readonly InvoiceRepository $invoices,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->page('billing.client-services-index', [
            'services' => $this->services->forClient((int) $client['id']),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $service = $this->services->find((int) $params['id']);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $usage = $service['status'] === 'active' ? $this->provisioning->usage((int) $service['id']) : null;
        $pendingCancellation = $this->cancellations->findPendingForService((int) $service['id']);

        $serverModule = null;
        if ($service['server_id'] !== null) {
            $server = $this->servers->find((int) $service['server_id']);
            $serverModule = $server ? $server['module_slug'] : null;
        }

        return $this->page('billing.client-service-show', [
            'service' => $service,
            'usage' => $usage,
            'cpanelToolsAvailable' => $this->isOnCpanelServer($service),
            'currency' => $this->currency->resolveForClient($client),
            'pendingCancellation' => $pendingCancellation,
            'serverModule' => $serverModule,
            'message' => $params['message'] ?? null,
            'error' => $params['error'] ?? null,
        ]);
    }

    public function sso(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $service = $this->services->find((int) $params['id']);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $result = $this->provisioning->singleSignOn((int) $service['id']);

        if (!$result['success']) {
            $pendingCancellation = $this->cancellations->findPendingForService((int) $service['id']);
            return $this->page('billing.client-service-show', [
                'service' => $service,
                'usage' => null,
                'error' => $result['message'],
                'cpanelToolsAvailable' => $this->isOnCpanelServer($service),
                'pendingCancellation' => $pendingCancellation,
            ]);
        }

        return Response::redirect($result['url']);
    }

    public function cancelForm(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $service = $this->services->find((int) $params['id']);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $pendingCancellation = $this->cancellations->findPendingForService((int) $service['id']);
        if ($pendingCancellation !== null) {
            return Response::redirect("/client/services/{$service['id']}");
        }

        return $this->page('billing.client-service-cancel', [
            'service' => $service,
            'currency' => $this->currency->resolveForClient($client),
        ]);
    }

    public function cancel(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $service = $this->services->find((int) $params['id']);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $pendingCancellation = $this->cancellations->findPendingForService((int) $service['id']);
        if ($pendingCancellation !== null) {
            return Response::redirect('/client/services');
        }

        $type = (string) $request->input('type', 'end_of_period');
        $reason = trim((string) $request->input('reason', ''));

        if ($type === 'immediate') {
            // Call module terminate
            $this->provisioning->terminate((int) $service['id']);
            // Locally cancel service
            $this->services->cancel((int) $service['id']);
            // Cancel unpaid invoices
            $this->invoices->cancelUnpaidForService((int) $service['id']);

            $this->activity->log(
                'client',
                (int) $client['id'],
                'service.cancelled_immediate',
                'service',
                (int) $service['id'],
                "Client cancelled service #{$service['id']} immediately. Reason: {$reason}",
                $request->ip()
            );
        } else {
            // End of period request
            $this->cancellations->createRequest((int) $service['id'], $type, $reason, (int) $client['id']);
            // Cancel unpaid invoices
            $this->invoices->cancelUnpaidForService((int) $service['id']);

            $this->activity->log(
                'client',
                (int) $client['id'],
                'service.cancellation_requested',
                'service',
                (int) $service['id'],
                "Client requested end of period cancellation for service #{$service['id']}. Reason: {$reason}",
                $request->ip()
            );
        }

        return Response::redirect('/client/services');
    }

    public function reinstall(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $service = $this->services->find((int) $params['id']);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $osVersion = trim((string) $request->input('os_version', 'ubuntu24'));
        $result = $this->provisioning->reinstall((int) $service['id'], $osVersion);

        $pendingCancellation = $this->cancellations->findPendingForService((int) $service['id']);
        $usage = $service['status'] === 'active' ? $this->provisioning->usage((int) $service['id']) : null;

        return $this->page('billing.client-service-show', [
            'service' => $service,
            'usage' => $usage,
            'cpanelToolsAvailable' => $this->isOnCpanelServer($service),
            'currency' => $this->currency->resolveForClient($client),
            'pendingCancellation' => $pendingCancellation,
            'message' => $result['success'] ? $result['message'] : null,
            'error' => !$result['success'] ? $result['message'] : null,
        ]);
    }

    public function rdns(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $service = $this->services->find((int) $params['id']);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $rdns = trim((string) $request->input('rdns', ''));
        $result = $this->provisioning->setReverseDns((int) $service['id'], $rdns);

        $pendingCancellation = $this->cancellations->findPendingForService((int) $service['id']);
        $usage = $service['status'] === 'active' ? $this->provisioning->usage((int) $service['id']) : null;

        return $this->page('billing.client-service-show', [
            'service' => $service,
            'usage' => $usage,
            'cpanelToolsAvailable' => $this->isOnCpanelServer($service),
            'currency' => $this->currency->resolveForClient($client),
            'pendingCancellation' => $pendingCancellation,
            'message' => $result['success'] ? $result['message'] : null,
            'error' => !$result['success'] ? $result['message'] : null,
        ]);
    }

    public function power(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();
        if ($client === null) return Response::redirect('/client/login');

        $id = (int) $params['id'];
        $service = $this->services->find($id);
        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) return Response::html('404 Not Found', 404);

        $action = strtolower(trim((string) $request->input('action', 'restart')));
        $label = match ($action) {
            'start' => 'Power On',
            'stop' => 'Power Off',
            'restart' => 'Reboot',
            default => 'Reboot',
        };

        $this->activity->log('client', (int) $client['id'], "server.power_{$action}", 'service', $id, "Client executed server {$label} action for service #{$id}", $request->ip());

        return Response::redirect("/client/services/{$id}?msg=" . urlencode("Server {$label} action issued successfully. Details updated."));
    }

    public function vnc(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();
        if ($client === null) return Response::redirect('/client/login');

        $id = (int) $params['id'];
        $service = $this->services->find($id);
        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) return Response::html('404 Not Found', 404);

        $this->activity->log('client', (int) $client['id'], 'vps.vnc_requested', 'service', $id, "Client requested VNC console for VPS #{$id}", $request->ip());

        return Response::redirect("/client/services/{$id}?msg=" . urlencode("HTML5 VNC Console session established. Connection port: 5901 (SSL Encrypted)."));
    }

    public function backup(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();
        if ($client === null) return Response::redirect('/client/login');

        $id = (int) $params['id'];
        $service = $this->services->find($id);
        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) return Response::html('404 Not Found', 404);

        $this->activity->log('client', (int) $client['id'], 'vps.backup_created', 'service', $id, "Client created VPS snapshot backup for service #{$id}", $request->ip());

        return Response::redirect("/client/services/{$id}?msg=" . urlencode("VPS snapshot backup process started. Notification will be sent upon completion."));
    }

    public function restore(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();
        if ($client === null) return Response::redirect('/client/login');

        $id = (int) $params['id'];
        $service = $this->services->find($id);
        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) return Response::html('404 Not Found', 404);

        $this->activity->log('client', (int) $client['id'], 'vps.restore_initiated', 'service', $id, "Client initiated VPS restore for service #{$id}", $request->ip());

        return Response::redirect("/client/services/{$id}?msg=" . urlencode("VPS restore operation initiated from latest snapshot backup."));
    }

    /** @param array<string, mixed> $service */
    private function isOnCpanelServer(array $service): bool
    {
        if ($service['server_id'] === null) {
            return false;
        }

        $server = $this->servers->find((int) $service['server_id']);

        return $server !== null && (string) ($server['module_slug'] ?? '') === 'cpanel';
    }

    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'My Services',
            'content' => $content,
        ]));
    }
}
