<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class RegistrarController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly RegistrarRepository $registrars
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('domains.registrars-index', ['registrars' => $this->registrars->all()]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Registrars',
            'content' => $content,
        ]));
    }

    public function toggle(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $registrar = $this->registrars->findBySlug((string) $params['slug']);

        if ($registrar !== null) {
            $this->registrars->setEnabled($registrar['slug'], !$registrar['is_enabled']);
        }

        return Response::redirect('/admin/registrars');
    }

    public function updateConfig(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $config = match ($slug) {
            'upperlink' => [
                'email' => trim((string) $request->input('email', '')),
                'api_key' => trim((string) $request->input('api_key', '')),
            ],
            'connectreseller' => [
                'api_key' => trim((string) $request->input('api_key', '')),
            ],
            'resellerclub' => [
                'reseller_id' => trim((string) $request->input('reseller_id', '')),
                'api_key' => trim((string) $request->input('api_key', '')),
                'customer_id' => trim((string) $request->input('customer_id', '')),
            ],
            'namecheap' => [
                'api_user' => trim((string) $request->input('api_user', '')),
                'api_key' => trim((string) $request->input('api_key', '')),
                'username' => trim((string) $request->input('username', '')),
                'client_ip' => trim((string) $request->input('client_ip', '')),
                'sandbox' => $request->input('sandbox') ? '1' : '',
            ],
            default => [],
        };

        $this->registrars->setConfig($slug, $config);

        return Response::redirect('/admin/registrars');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::DOMAINS_MANAGE)) {
            return Response::html('403 Forbidden — missing domains.manage permission', 403);
        }

        return null;
    }
}
