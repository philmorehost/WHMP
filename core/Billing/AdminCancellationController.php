<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class AdminCancellationController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly CancellationRequestRepository $cancellations,
        private readonly ServiceRepository $services,
        private readonly ProvisioningService $provisioning,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $requests = $this->cancellations->allPending();

        return $this->render('billing.admin-cancellations', [
            'requests' => $requests,
        ]);
    }

    public function process(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $requestData = $this->cancellations->find($id);

        if ($requestData === null) {
            return Response::html('404 Not Found', 404);
        }

        $serviceId = (int) $requestData['service_id'];

        // Terminate on the provider's side
        $this->provisioning->terminate($serviceId);

        // Terminate locally (cancel service)
        $this->services->cancel($serviceId);

        // Mark request as processed
        $this->cancellations->markProcessed($id);

        // Log admin activity
        $admin = $this->guard->currentAdmin();
        $this->activity->log(
            'admin',
            (int) $admin['id'],
            'service.cancelled_manually',
            'service',
            $serviceId,
            "Admin manually processed end-of-period cancellation for service #{$serviceId}",
            $request->ip()
        );

        return Response::redirect('/admin/cancellations');
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
            'title' => 'CodeVault Admin — Cancellation Requests',
            'content' => $content,
        ]));
    }
}
