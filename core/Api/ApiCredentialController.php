<?php

declare(strict_types=1);

namespace CodeVault\Api;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Admin management screen for external API credentials (blueprint §3
 * "scoped API credentials/roles"). Issue a key/secret pair with a chosen
 * set of scopes; the plaintext secret is shown exactly once (after that
 * only its hash is stored), matching how the WHMCS API key model works.
 */
final class ApiCredentialController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly DatabaseApiCredentialRepository $credentials
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('api.credentials-index', [
            'credentials' => $this->credentials->all(),
            'scopeCatalog' => DatabaseApiCredentialRepository::scopeCatalog(),
            'newCredential' => $request->query('created'),
            'newSecret' => $request->query('secret'),
            'error' => $request->query('error'),
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $admin = $this->guard->currentAdmin();
        $label = trim((string) $request->input('label', ''));
        $rawScopes = $request->input('scopes');

        $scopes = [];
        if (is_array($rawScopes)) {
            $catalog = DatabaseApiCredentialRepository::scopeCatalog();
            foreach ($rawScopes as $scope) {
                $scope = (string) $scope;
                if (in_array($scope, $catalog, true) && !in_array($scope, $scopes, true)) {
                    $scopes[] = $scope;
                }
            }
        }

        if ($label === '' || $scopes === []) {
            return Response::redirect('/admin/api-credentials?error=' . urlencode('A label and at least one scope are required.'));
        }

        $created = $this->credentials->create($label, $scopes, $admin !== null ? (int) $admin['id'] : null);

        return Response::redirect(
            '/admin/api-credentials?created=' . urlencode($label) . '&secret=' . urlencode($created['key'] . '.' . $created['secret'])
        );
    }

    public function setActive(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $credential = $this->credentials->find((int) $params['id']);

        if ($credential !== null) {
            $this->credentials->setActive((int) $params['id'], $request->input('active') === '1');
        }

        return Response::redirect('/admin/api-credentials');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->credentials->delete((int) $params['id']);

        return Response::redirect('/admin/api-credentials');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::SETTINGS_MANAGE)) {
            return Response::html('403 Forbidden — missing settings.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'API Credentials',
            'content' => $content,
        ]));
    }
}
