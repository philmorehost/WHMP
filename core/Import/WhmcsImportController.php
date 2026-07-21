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
        $isAjax = $request->header('X-Requested-With') === 'XMLHttpRequest' || $request->input('ajax') === '1';

        // For an AJAX submit, an expired session/insufficient permission
        // must come back as JSON — otherwise the browser receives a 302
        // redirect to /login (or a 403 HTML page) that the migrator's
        // fetch() can't parse, and the real cause ("you're logged out")
        // gets buried under a generic "lost connection" message.
        if ($denied = $this->requirePermission()) {
            if ($isAjax) {
                return Response::json([
                    'success' => false,
                    'message' => 'Your admin session has expired or you lack permission to run imports. Log back in, reload the migrator page, and try again.',
                ], 403);
            }
            return $denied;
        }

        $host = trim((string) $request->input('host', '127.0.0.1'));
        $port = (int) $request->input('port', 3306);
        $database = trim((string) $request->input('database', ''));
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $prefix = trim((string) $request->input('prefix', ''));
        $overwrite = $request->input('overwrite') === '1';
        // Per-attempt identifier the frontend generates and echoes back so
        // it can distinguish this run's progress from a previous run's
        // leftover result in the persisted progress file.
        $runId = trim((string) $request->input('run_id', ''));

        if ($database === '' || $username === '') {
            if ($isAjax) {
                return Response::json(['success' => false, 'message' => 'Database name and username are required fields.']);
            }
            return $this->render('import.whmcs', [
                'result' => null,
                'error' => 'Database name and username are required fields.',
                'runs' => $this->runs->recentByType('whmcs'),
            ]);
        }

        session_write_close(); // Release session lock so progress AJAX requests can run concurrently

        try {
            $result = $this->importer->import([
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'prefix' => $prefix,
                'run_id' => $runId,
            ], $overwrite);

            // Re-start session if needed, but since we are redirecting/responding we can just log the run
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

            if ($isAjax) {
                return Response::json($result);
            }

            return $this->render('import.whmcs', [
                'result' => $result,
                'error' => null,
                'runs' => $this->runs->recentByType('whmcs'),
            ]);
        } catch (\Throwable $e) {
            $logFile = dirname(__DIR__, 2) . '/storage/migration_error.log';
            $errorMsg = $e->getMessage() . "\n" . $e->getTraceAsString();
            file_put_contents($logFile, $errorMsg);

            $errorResult = [
                'success' => false,
                'message' => 'Migration failed with fatal error: ' . $e->getMessage(),
                'imported' => [
                    'clients' => 0, 'servers' => 0, 'products' => 0, 'services' => 0,
                    'domains' => 0, 'invoices' => 0, 'transactions' => 0, 'currencies' => 0,
                    'tax_rules' => 0, 'contacts' => 0, 'configurable_options' => 0,
                    'departments' => 0, 'tickets' => 0, 'promotions' => 0, 'domain_pricing' => 0
                ],
                'errors' => [['row' => 0, 'reason' => $e->getMessage()]],
            ];

            if ($isAjax) {
                return Response::json($errorResult);
            }

            return $this->render('import.whmcs', [
                'result' => null,
                'error' => 'Migration failed with fatal error: ' . $e->getMessage(),
                'runs' => $this->runs->recentByType('whmcs'),
            ]);
        }
    }

    public function progress(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $file = dirname(__DIR__, 2) . '/storage/migration_progress.json';
        $data = [];
        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true) ?: [];
        }

        return Response::json($data);
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
