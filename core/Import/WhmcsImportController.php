<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class WhmcsImportController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly WhmcsImportService $importer,
        private readonly ImportRunRepository $runs
    ) {
    }

    public function form(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('import.whmcs', [
            'result' => null,
            'error' => null,
            'runs' => $this->runs->recentByType('whmcs'),
        ]);
    }

    public function run(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $host = trim((string) $request->input('host', '127.0.0.1'));
        $port = (int) $request->input('port', 3306);
        $database = trim((string) $request->input('database', ''));
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $prefix = trim((string) $request->input('prefix', ''));

        if ($database === '' || $username === '') {
            return $this->render('import.whmcs', [
                'result' => null,
                'error' => 'Database name and username are required fields.',
                'runs' => $this->runs->recentByType('whmcs'),
            ]);
        }

        $result = $this->importer->import([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'prefix' => $prefix,
        ]);

        if (!$result['success']) {
            return $this->render('import.whmcs', [
                'result' => null,
                'error' => $result['message'],
                'runs' => $this->runs->recentByType('whmcs'),
            ]);
        }

        $adminId = (int) $this->guard->currentAdmin()['id'];
        $totalImported = array_sum($result['imported']);

        $this->runs->create(
            $adminId,
            'whmcs',
            "Database: {$database}",
            $totalImported,
            $totalImported,
            0,
            $result['errors']
        );

        return $this->render('import.whmcs', [
            'result' => $result,
            'error' => null,
            'runs' => $this->runs->recentByType('whmcs'),
        ]);
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
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — WHMCS Migrator',
            'content' => $content,
        ]));
    }
}
