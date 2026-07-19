<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ImportController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly CsvParser $parser,
        private readonly ClientImportService $importer,
        private readonly ImportRunRepository $runs
    ) {
    }

    public function form(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('import.clients', [
            'result' => null,
            'error' => null,
            'runs' => $this->runs->recentByType('clients'),
        ]);
    }

    public function run(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $file = $request->file('csv');

        if ($file === null) {
            return $this->render('import.clients', [
                'result' => null,
                'error' => 'Choose a CSV file to upload.',
                'runs' => $this->runs->recentByType('clients'),
            ]);
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return $this->render('import.clients', [
                'result' => null,
                'error' => 'The upload failed (error code ' . $file['error'] . ') — the file may be larger than this server allows.',
                'runs' => $this->runs->recentByType('clients'),
            ]);
        }

        $content = file_get_contents((string) $file['tmp_name']);

        if ($content === false || trim($content) === '') {
            return $this->render('import.clients', [
                'result' => null,
                'error' => 'The uploaded file is empty or could not be read.',
                'runs' => $this->runs->recentByType('clients'),
            ]);
        }

        $parsed = $this->parser->parse($content);

        if ($parsed['headers'] === []) {
            return $this->render('import.clients', [
                'result' => null,
                'error' => 'The uploaded file has no header row.',
                'runs' => $this->runs->recentByType('clients'),
            ]);
        }

        $result = $this->importer->import($parsed['headers'], $parsed['rows']);

        if (isset($result['error'])) {
            return $this->render('import.clients', [
                'result' => null,
                'error' => $result['error'],
                'runs' => $this->runs->recentByType('clients'),
            ]);
        }

        $adminId = (int) $this->guard->currentAdmin()['id'];
        $this->runs->create($adminId, 'clients', (string) $file['name'], count($parsed['rows']), $result['imported'], $result['skipped'], $result['errors']);

        return $this->render('import.clients', [
            'result' => $result,
            'error' => null,
            'runs' => $this->runs->recentByType('clients'),
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
            'title' => 'CodeVault Admin — Import Clients',
            'content' => $content,
        ]));
    }
}
