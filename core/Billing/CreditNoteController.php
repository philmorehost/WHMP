<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Pdf\CreditNotePdfBuilder;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/** Admin-side Credit Notes (blueprint §4.3 Billing "Credit & Debit Notes", R18). */
final class CreditNoteController
{
    /** Fixed line-item row count — no JS "add another row" widget, consistent with this app's other simple admin forms. */
    private const MAX_ITEM_ROWS = 5;

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly CreditNoteRepository $creditNotes,
        private readonly CreditNoteService $creditNoteService,
        private readonly ClientRepository $clients,
        private readonly CreditNotePdfBuilder $pdf,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('billing.credit-notes-index', ['creditNotes' => $this->creditNotes->all()]);
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = $request->query('client_id') !== null ? (int) $request->query('client_id') : null;
        $client = $clientId !== null ? $this->clients->find($clientId) : null;

        return $this->render('billing.credit-note-form', [
            'client' => $client,
            'invoiceId' => $request->query('invoice_id'),
            'error' => null,
            'maxRows' => self::MAX_ITEM_ROWS,
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) $request->input('client_id', 0);
        $client = $clientId > 0 ? $this->clients->find($clientId) : null;

        if ($client === null) {
            $email = trim((string) $request->input('client_email', ''));
            $client = $email !== '' ? $this->clients->findByEmail($email) : null;
        }

        if ($client === null) {
            return $this->render('billing.credit-note-form', [
                'client' => null,
                'invoiceId' => $request->input('invoice_id'),
                'error' => 'No matching client found.',
                'maxRows' => self::MAX_ITEM_ROWS,
            ]);
        }

        $invoiceIdInput = trim((string) $request->input('invoice_id', ''));
        $invoiceId = $invoiceIdInput !== '' ? (int) $invoiceIdInput : null;
        $reason = trim((string) $request->input('reason', ''));
        $items = $this->extractItems($request);

        $result = $this->creditNoteService->issue((int) $client['id'], $invoiceId, $reason, $items, (int) $this->guard->currentAdmin()['id']);

        if (!$result['success']) {
            return $this->render('billing.credit-note-form', [
                'client' => $client,
                'invoiceId' => $invoiceId,
                'error' => $result['error'],
                'maxRows' => self::MAX_ITEM_ROWS,
            ]);
        }

        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'credit_note.issued', 'client', (int) $client['id'], "Issued credit note #{$result['id']} for client #{$client['id']}", $request->ip());

        return Response::redirect("/admin/credit-notes/{$result['id']}");
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $creditNote = $this->creditNotes->find((int) $params['id']);

        if ($creditNote === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('billing.credit-note-show', [
            'creditNote' => $creditNote,
            'items' => $this->creditNotes->items((int) $creditNote['id']),
            'client' => $this->clients->find((int) $creditNote['client_id']),
        ]);
    }

    public function downloadPdf(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $creditNote = $this->creditNotes->find((int) $params['id']);

        if ($creditNote === null) {
            return Response::html('404 Not Found', 404);
        }

        $client = $this->clients->find((int) $creditNote['client_id']);
        $bytes = $this->pdf->build($creditNote, $this->creditNotes->items((int) $creditNote['id']), $client ?? []);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', "inline; filename=\"credit-note-CN-{$creditNote['id']}.pdf\"")
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    /** @return array<int, array{description: string, amount: float}> */
    private function extractItems(Request $request): array
    {
        $descriptions = (array) $request->input('item_description', []);
        $amounts = (array) $request->input('item_amount', []);
        $items = [];

        foreach ($descriptions as $i => $description) {
            $description = trim((string) $description);
            $amount = (float) ($amounts[$i] ?? 0);

            if ($description !== '' && $amount > 0) {
                $items[] = ['description' => $description, 'amount' => $amount];
            }
        }

        return $items;
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::INVOICES_MANAGE)) {
            return Response::html('403 Forbidden — missing invoices.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Credit Notes',
            'content' => $content,
        ]));
    }
}
