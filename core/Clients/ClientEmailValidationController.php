<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ClientEmailValidationController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ClientEmailValidationRepository $results,
        private readonly ClientEmailValidationService $scanner
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render([
            'results' => $this->results->all(),
            'summary' => $this->results->summary(),
            'scanned' => $request->query('scanned'),
        ]);
    }

    public function scan(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $outcome = $this->scanner->scanAll();

        return Response::redirect('/admin/email-validation?scanned=' . $outcome['invalid'] . '-' . $outcome['total']);
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::CLIENTS_MANAGE)) {
            return Response::html('403 Forbidden — missing clients.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): Response
    {
        $content = $this->view->render('clients.email-validation', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Email Validation',
            'content' => $content,
        ]));
    }
}
