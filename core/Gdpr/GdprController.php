<?php

declare(strict_types=1);

namespace CodeVault\Gdpr;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class GdprController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly GdprRequestRepository $requests,
        private readonly GdprSettings $settings,
        private readonly DataExportService $exportService,
        private readonly DataErasureService $erasureService,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render([
            'requests' => $this->requests->all(),
            'retention' => $this->settings->get(),
            'error' => null,
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->settings->save(
            (int) $request->input('activity_log_days', 730),
            (int) $request->input('login_attempts_days', 90),
            (int) $request->input('email_log_days', 365)
        );

        return Response::redirect('/admin/gdpr');
    }

    public function process(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $gdprRequest = $this->requests->find((int) $params['id']);

        if ($gdprRequest === null || $gdprRequest['status'] !== 'pending') {
            return Response::redirect('/admin/gdpr');
        }

        $adminId = (int) $this->guard->currentAdmin()['id'];
        $clientId = (int) $gdprRequest['client_id'];

        if ($gdprRequest['type'] === 'export') {
            $export = $this->exportService->export($clientId);

            if ($export === null) {
                return $this->render([
                    'requests' => $this->requests->all(),
                    'retention' => $this->settings->get(),
                    'error' => "Client #{$clientId} no longer exists — cannot generate an export.",
                ]);
            }

            $json = (string) json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->requests->markCompleted((int) $gdprRequest['id'], $adminId, $json, null);
            $this->activity->log('admin', $adminId, 'gdpr.export_processed', 'client', $clientId, "Processed a GDPR data export for client #{$clientId}", $request->ip());
        } else {
            $erased = $this->erasureService->erase($clientId);

            if (!$erased) {
                return $this->render([
                    'requests' => $this->requests->all(),
                    'retention' => $this->settings->get(),
                    'error' => "Client #{$clientId} no longer exists — cannot process erasure.",
                ]);
            }

            $this->requests->markCompleted((int) $gdprRequest['id'], $adminId, null, null);
            $this->activity->log('admin', $adminId, 'gdpr.erasure_processed', 'client', $clientId, "Processed a GDPR erasure for client #{$clientId}", $request->ip());
        }

        return Response::redirect('/admin/gdpr');
    }

    public function reject(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $gdprRequest = $this->requests->find((int) $params['id']);

        if ($gdprRequest === null || $gdprRequest['status'] !== 'pending') {
            return Response::redirect('/admin/gdpr');
        }

        $adminId = (int) $this->guard->currentAdmin()['id'];
        $notes = trim((string) $request->input('notes', '')) ?: null;

        $this->requests->markRejected((int) $gdprRequest['id'], $adminId, $notes);
        $this->activity->log('admin', $adminId, 'gdpr.request_rejected', 'client', (int) $gdprRequest['client_id'], "Rejected a GDPR {$gdprRequest['type']} request for client #{$gdprRequest['client_id']}", $request->ip());

        return Response::redirect('/admin/gdpr');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::PRIVACY_MANAGE)) {
            return Response::html('403 Forbidden — missing privacy.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): Response
    {
        $content = $this->view->render('gdpr.index', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — GDPR / Privacy',
            'content' => $content,
        ]));
    }
}
