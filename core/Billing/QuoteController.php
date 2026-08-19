<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Pdf\QuotePdfBuilder;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/** Admin-side Quotes (blueprint §4.1 "My Quotes", §4.3 Billing menu, R23). */
final class QuoteController
{
    /** Fixed line-item row count — matches R18's credit-note form, no JS "add another row" widget. */
    private const MAX_ITEM_ROWS = 5;

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly QuoteRepository $quotes,
        private readonly QuoteService $quoteService,
        private readonly ClientRepository $clients,
        private readonly QuotePdfBuilder $pdf,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $page = max(1, (int) $request->query('page', 1));
        $filters = \CodeVault\Table\TableFilters::fromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['id' => true, 'client' => true, 'subject' => true, 'total' => true, 'status' => true, 'valid_until' => true]
        );

        $results = $this->quotes->paginate($page, 15, $filters);

        return $this->render('billing.quotes-index', [
            'quotes' => $results['data'],
            'results' => $results,
            'filters' => $filters,
            'filterColumns' => [
                ['filterable' => true, 'key' => 'id', 'label' => 'Quote ID', 'type' => 'number', 'placeholder' => 'e.g. 5'],
                ['filterable' => true, 'key' => 'client', 'label' => 'Client', 'type' => 'text', 'placeholder' => 'Name or email'],
                ['filterable' => true, 'key' => 'subject', 'label' => 'Subject', 'type' => 'text', 'placeholder' => 'Subject'],
                ['filterable' => true, 'key' => 'total', 'label' => 'Total', 'type' => 'number', 'placeholder' => 'e.g. 19.99'],
                ['filterable' => true, 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => [
                    'draft' => 'Draft',
                    'sent' => 'Sent',
                    'accepted' => 'Accepted',
                    'declined' => 'Declined',
                ]],
                ['filterable' => true, 'key' => 'valid_until', 'label' => 'Valid Until', 'type' => 'text', 'placeholder' => 'YYYY-MM-DD'],
                ['filterable' => false],
            ],
        ]);
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = $request->query('client_id') !== null ? (int) $request->query('client_id') : null;
        $client = $clientId !== null ? $this->clients->find($clientId) : null;

        return $this->render('billing.quote-form', [
            'client' => $client,
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
            return $this->render('billing.quote-form', [
                'client' => null,
                'error' => 'No matching client found.',
                'maxRows' => self::MAX_ITEM_ROWS,
            ]);
        }

        $subject = trim((string) $request->input('subject', ''));
        $validUntil = trim((string) $request->input('valid_until', '')) ?: null;
        $items = $this->extractItems($request);

        $result = $this->quoteService->create((int) $client['id'], $subject, $validUntil, $items, (int) $this->guard->currentAdmin()['id']);

        if (!$result['success']) {
            return $this->render('billing.quote-form', [
                'client' => $client,
                'error' => $result['error'],
                'maxRows' => self::MAX_ITEM_ROWS,
            ]);
        }

        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'quote.created', 'client', (int) $client['id'], "Created quote #{$result['id']} for client #{$client['id']}", $request->ip());

        return Response::redirect("/admin/quotes/{$result['id']}");
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $quote = $this->quotes->find((int) $params['id']);

        if ($quote === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('billing.quote-show', [
            'quote' => $quote,
            'items' => $this->quotes->items((int) $quote['id']),
            'client' => $this->clients->find((int) $quote['client_id']),
        ]);
    }

    public function send(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $result = $this->quoteService->send($id);

        if ($result['success']) {
            $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'quote.sent', 'quote', $id, "Sent quote #{$id} to the client", $request->ip());
        }

        return Response::redirect("/admin/quotes/{$id}");
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $this->quotes->delete($id);

        return Response::redirect('/admin/quotes');
    }

    public function downloadPdf(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $quote = $this->quotes->find((int) $params['id']);

        if ($quote === null) {
            return Response::html('404 Not Found', 404);
        }

        $client = $this->clients->find((int) $quote['client_id']);
        $bytes = $this->pdf->build($quote, $this->quotes->items((int) $quote['id']), $client ?? []);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', "inline; filename=\"quote-Q-{$quote['id']}.pdf\"")
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
            'title' => 'CodeVault Admin — Quotes',
            'content' => $content,
        ]));
    }
}
