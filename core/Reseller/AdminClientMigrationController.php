<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Auth\AuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Staff\RoleRepository;
use CodeVault\View;

/**
 * Client moves between providers: the request queue, the review page, and the button.
 *
 * READ with resellers.manage; DECIDE (move or decline) as a SUPER ADMIN only. Moving a
 * customer from one reseller to another changes two businesses' customer lists and
 * where future revenue goes — the request was for the super admin to make that call,
 * and a permission that can be granted to any support role is the wrong gate for it.
 *
 * Every move goes through a review page first (GET, changes nothing) that shows what
 * will move and what will not; the button posts back the same client and destination,
 * and ClientMigrationService re-checks all of it inside its transaction.
 */
final class AdminClientMigrationController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly RoleRepository $roles,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ClientMigrationService $service,
        private readonly ClientMigrationRepository $migrations,
        private readonly ClientRepository $clients
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('reseller.admin-migrations', [
            'pending' => $this->migrations->pending(),
            'decided' => $this->migrations->decided(),
            'destinations' => $this->service->destinations(),
            'canDecide' => $this->isSuperAdmin(),
            'notice' => $this->session->pullFlash('migration_notice'),
            'error' => $this->session->pullFlash('migration_error'),
        ]);
    }

    /**
     * The review page. Reached from a pending request (?request=ID), from the "move a
     * client" form (?client=ID-or-email&to=...), or from a client's profile.
     */
    public function review(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $requestRow = null;
        $requestId = (int) $request->query('request', '0');

        if ($requestId > 0) {
            $requestRow = $this->migrations->find($requestId);

            if ($requestRow === null) {
                return $this->back('migration_error', 'That request no longer exists.');
            }
        }

        // The client: from the request when there is one, else as typed — an id or an
        // email address, whichever the admin had to hand.
        $clientInput = trim((string) $request->query('client', ''));
        $client = null;

        if ($requestRow !== null) {
            $client = !empty($requestRow['client_id'])
                ? $this->clients->find((int) $requestRow['client_id'])
                // A reseller asked for an address that had no account at the time; it
                // may have one now.
                : $this->clients->findByEmail((string) $requestRow['client_email']);
        } elseif ($clientInput !== '') {
            $client = ctype_digit($clientInput)
                ? $this->clients->find((int) $clientInput)
                : $this->clients->findByEmail($clientInput);
        }

        if ($requestRow !== null) {
            $target = (string) $requestRow['target'];
            $toStoreId = !empty($requestRow['to_reseller_id']) ? (int) $requestRow['to_reseller_id'] : null;

            // A store the request named has since been deleted: the row says 'store'
            // but the id is gone. Do not let that read as "the platform".
            if ($target === ClientMigrationService::TARGET_STORE && $toStoreId === null) {
                $preview = ['ok' => false, 'error' => 'The store this request named no longer exists. Decline the request, or move the client from the form instead.'];
            }
        } else {
            [$target, $toStoreId] = ClientMigrationService::parseDestination((string) $request->query('to', ClientMigrationService::TARGET_PLATFORM));
        }

        if (!isset($preview)) {
            $preview = $client === null
                ? ['ok' => false, 'error' => $requestRow !== null
                    ? 'No account exists for ' . (string) $requestRow['client_email'] . '. Decline the request, or wait until the customer has registered.'
                    : 'No client matches "' . $clientInput . '".']
                : $this->service->preview((int) $client['id'], $target, $toStoreId);
        }

        return $this->render('reseller.admin-migration-review', [
            'preview' => $preview,
            'client' => $client,
            'clientInput' => $clientInput,
            'requestRow' => $requestRow,
            'target' => $target,
            'toStoreId' => $toStoreId,
            'destinations' => $this->service->destinations(),
            'canDecide' => $this->isSuperAdmin(),
        ]);
    }

    public function execute(Request $request): Response
    {
        if ($denied = $this->requireSuperAdmin()) {
            return $denied;
        }

        $requestId = (int) $request->input('request_id', '0');
        [$target, $toStoreId] = ClientMigrationService::parseDestination((string) $request->input('to', ''));

        $result = $this->service->migrate(
            (int) $request->input('client_id', '0'),
            $target,
            $toStoreId,
            (int) ($this->guard->currentAdmin()['id'] ?? 0),
            $requestId > 0 ? $requestId : null,
            (string) $request->input('note', '')
        );

        return $result['success']
            ? $this->back('migration_notice', 'Client moved (move #' . (int) $result['migrationId'] . '). Their services, domains, invoices and tickets now belong to the new provider.')
            : $this->back('migration_error', (string) $result['error']);
    }

    public function reject(Request $request, array $params): Response
    {
        if ($denied = $this->requireSuperAdmin()) {
            return $denied;
        }

        $admin = $this->guard->currentAdmin() ?? [];

        $result = $this->service->reject(
            (int) ($params['id'] ?? 0),
            (int) ($admin['id'] ?? 0),
            (string) ($admin['display_name'] ?? $admin['username'] ?? ''),
            (string) $request->input('note', '')
        );

        return $result['success']
            ? $this->back('migration_notice', 'Request declined. Whoever asked has been told on their ticket.')
            : $this->back('migration_error', (string) $result['error']);
    }

    // ------------------------------------------------------------- internals ---

    private function isSuperAdmin(): bool
    {
        $admin = $this->guard->currentAdmin();

        if ($admin === null || empty($admin['role_id'])) {
            return false;
        }

        $role = $this->roles->find((int) $admin['role_id']);

        return $role !== null && (bool) $role['is_super_admin'];
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::RESELLERS_MANAGE)) {
            return Response::html('403 Forbidden — missing resellers.manage permission', 403);
        }

        return null;
    }

    private function requireSuperAdmin(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->isSuperAdmin()) {
            return Response::html('403 Forbidden — only a super admin can move clients between providers', 403);
        }

        return null;
    }

    private function back(string $key, string $message): Response
    {
        $this->session->flash($key, $message);

        return Response::redirect('/admin/resellers/migrations');
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Client migrations',
            'content' => $content,
        ]));
    }
}
