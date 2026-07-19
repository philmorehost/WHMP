<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Extends R16's clients-only CSV import screen (ImportController) to the
 * three entity types the blueprint always named as the eventual scope —
 * services, invoices, transactions — via a `{type}` route param rather
 * than three near-identical copy-pasted controllers. Each type has its own
 * importer service and its own admin-manage permission (matching the
 * permission each entity's own CRUD screen already gates on), resolved
 * here rather than genericizing ImportController itself, which stays
 * untouched and clients-specific.
 */
final class EntityImportController
{
    /** @var array<string, string> type => required permission */
    private const PERMISSIONS = [
        'services' => PermissionRegistry::ORDERS_MANAGE,
        'invoices' => PermissionRegistry::INVOICES_MANAGE,
        'transactions' => PermissionRegistry::INVOICES_MANAGE,
        'products' => PermissionRegistry::PRODUCTS_MANAGE,
        'tlds' => PermissionRegistry::DOMAINS_MANAGE,
    ];

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly CsvParser $parser,
        private readonly ServiceImportService $serviceImporter,
        private readonly InvoiceImportService $invoiceImporter,
        private readonly TransactionImportService $transactionImporter,
        private readonly ProductImportService $productImporter,
        private readonly DomainPricingImportService $tldImporter,
        private readonly ImportRunRepository $runs
    ) {
    }

    public function form(Request $request, array $params): Response
    {
        $type = (string) $params['type'];

        if ($denied = $this->requirePermission($type)) {
            return $denied;
        }

        return $this->render($type, [
            'result' => null,
            'error' => null,
            'runs' => $this->runs->recentByType($type),
        ]);
    }

    public function run(Request $request, array $params): Response
    {
        $type = (string) $params['type'];

        if ($denied = $this->requirePermission($type)) {
            return $denied;
        }

        $file = $request->file('csv');

        if ($file === null) {
            return $this->render($type, ['result' => null, 'error' => 'Choose a CSV file to upload.', 'runs' => $this->runs->recentByType($type)]);
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return $this->render($type, [
                'result' => null,
                'error' => 'The upload failed (error code ' . $file['error'] . ') — the file may be larger than this server allows.',
                'runs' => $this->runs->recentByType($type),
            ]);
        }

        $content = file_get_contents((string) $file['tmp_name']);

        if ($content === false || trim($content) === '') {
            return $this->render($type, ['result' => null, 'error' => 'The uploaded file is empty or could not be read.', 'runs' => $this->runs->recentByType($type)]);
        }

        $parsed = $this->parser->parse($content);

        if ($parsed['headers'] === []) {
            return $this->render($type, ['result' => null, 'error' => 'The uploaded file has no header row.', 'runs' => $this->runs->recentByType($type)]);
        }

        $result = $this->importerFor($type)->import($parsed['headers'], $parsed['rows']);

        if (isset($result['error'])) {
            return $this->render($type, ['result' => null, 'error' => $result['error'], 'runs' => $this->runs->recentByType($type)]);
        }

        $adminId = (int) $this->guard->currentAdmin()['id'];
        $this->runs->create($adminId, $type, (string) $file['name'], count($parsed['rows']), $result['imported'], $result['skipped'], $result['errors']);

        return $this->render($type, ['result' => $result, 'error' => null, 'runs' => $this->runs->recentByType($type)]);
    }

    private function importerFor(string $type): ServiceImportService|InvoiceImportService|TransactionImportService|ProductImportService|DomainPricingImportService
    {
        return match ($type) {
            'services' => $this->serviceImporter,
            'invoices' => $this->invoiceImporter,
            'transactions' => $this->transactionImporter,
            'products' => $this->productImporter,
            'tlds' => $this->tldImporter,
        };
    }

    private function requirePermission(string $type): ?Response
    {
        if (!isset(self::PERMISSIONS[$type])) {
            return Response::html('404 Not Found', 404);
        }

        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(self::PERMISSIONS[$type])) {
            return Response::html('403 Forbidden — missing ' . self::PERMISSIONS[$type] . ' permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $type, array $data): Response
    {
        $content = $this->view->render("import.{$type}", $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Import ' . ucfirst($type),
            'content' => $content,
        ]));
    }
}
