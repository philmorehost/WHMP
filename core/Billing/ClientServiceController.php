<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

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
        private readonly ProvisioningService $provisioning
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

        return $this->page('billing.client-service-show', [
            'service' => $service,
            'usage' => $usage,
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
            return $this->page('billing.client-service-show', [
                'service' => $service,
                'usage' => null,
                'error' => $result['message'],
            ]);
        }

        return Response::redirect($result['url']);
    }

    public function cancel(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $service = $this->services->find((int) $params['id']);

        if ($service !== null && (int) $service['client_id'] === (int) $client['id']) {
            $this->services->cancel((int) $service['id']);
        }

        return Response::redirect('/client/services');
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
