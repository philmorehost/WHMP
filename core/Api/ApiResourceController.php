<?php

declare(strict_types=1);

namespace CodeVault\Api;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Domains\DomainRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;

/**
 * The external REST API (blueprint §3 — WHMCS-parity "300+ actions" is
 * aspirational; this is the real, working core). Every route under
 * /api/* authenticates via ApiAuthenticator (Bearer key.secret) and
 * checks the requested scope before doing work. The CSRF exemption for
 * /api/* already exists in Kernel::requiresCsrfCheck() because Bearer
 * auth replaces the session credential.
 *
 * Response shape is the frozen ApiResponse envelope: {status, data} on
 * success, {status, message, code} on error.
 */
final class ApiResourceController
{
    public function __construct(
        private readonly ApiAuthenticator $auth,
        private readonly ClientRepository $clients,
        private readonly InvoiceRepository $invoices,
        private readonly ServiceRepository $services,
        private readonly DomainRepository $domains,
        private readonly TicketRepository $tickets,
        private readonly TransactionRepository $transactions,
        private readonly TicketService $ticketService
    ) {
    }

    /** GET /api/clients — list clients (paginated). */
    public function clients(Request $request): Response
    {
        try {
            $credential = $this->auth->authenticate($request);
            $this->auth->authorize($credential, 'clients.read');
        } catch (ApiAuthException $e) {
            return ApiResponse::error($e->getMessage(), 'UNAUTHORIZED', 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $search = trim((string) $request->query('search', ''));

        $pagination = $this->clients->paginate($search, $page, $perPage);

        return ApiResponse::success($pagination);
    }

    /** GET /api/clients/{id} — one client. */
    public function client(Request $request, array $params): Response
    {
        try {
            $credential = $this->auth->authenticate($request);
            $this->auth->authorize($credential, 'clients.read');
        } catch (ApiAuthException $e) {
            return ApiResponse::error($e->getMessage(), 'UNAUTHORIZED', 401);
        }

        $client = $this->clients->find((int) $params['id']);

        if ($client === null) {
            return ApiResponse::error('Client not found.', 'NOT_FOUND', 404);
        }

        return ApiResponse::success(['client' => $client]);
    }

    /** GET /api/invoices — list invoices (paginated, optional status filter). */
    public function invoices(Request $request): Response
    {
        try {
            $credential = $this->auth->authenticate($request);
            $this->auth->authorize($credential, 'invoices.read');
        } catch (ApiAuthException $e) {
            return ApiResponse::error($e->getMessage(), 'UNAUTHORIZED', 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $status = trim((string) $request->query('status', ''));
        $status = in_array($status, ['unpaid', 'paid', 'cancelled', 'refunded'], true) ? $status : null;

        return ApiResponse::success($this->invoices->paginate($status, $page, $perPage));
    }

    /** GET /api/invoices/{id} — one invoice with line items + transactions. */
    public function invoice(Request $request, array $params): Response
    {
        try {
            $credential = $this->auth->authenticate($request);
            $this->auth->authorize($credential, 'invoices.read');
        } catch (ApiAuthException $e) {
            return ApiResponse::error($e->getMessage(), 'UNAUTHORIZED', 401);
        }

        $invoice = $this->invoices->find((int) $params['id']);

        if ($invoice === null) {
            return ApiResponse::error('Invoice not found.', 'NOT_FOUND', 404);
        }

        return ApiResponse::success([
            'invoice' => $invoice,
            'items' => $this->invoices->items((int) $invoice['id']),
            'transactions' => $this->transactions->forInvoice((int) $invoice['id']),
        ]);
    }

    /** GET /api/services — list services (paginated, optional status). */
    public function services(Request $request): Response
    {
        try {
            $credential = $this->auth->authenticate($request);
            $this->auth->authorize($credential, 'services.read');
        } catch (ApiAuthException $e) {
            return ApiResponse::error($e->getMessage(), 'UNAUTHORIZED', 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $status = trim((string) $request->query('status', ''));
        $status = $status !== '' ? $status : null;

        return ApiResponse::success($this->services->paginate($status, $page, $perPage));
    }

    /** GET /api/domains — list domains (paginated). */
    public function domains(Request $request): Response
    {
        try {
            $credential = $this->auth->authenticate($request);
            $this->auth->authorize($credential, 'domains.read');
        } catch (ApiAuthException $e) {
            return ApiResponse::error($e->getMessage(), 'UNAUTHORIZED', 401);
        }

        $db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        $total = (int) ($db->selectOne('SELECT COUNT(*) AS c FROM domains')['c'] ?? 0);
        $rows = $db->select('SELECT * FROM domains ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);

        return ApiResponse::success(['data' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage]);
    }

    /** GET /api/tickets — list tickets (paginated). */
    public function tickets(Request $request): Response
    {
        try {
            $credential = $this->auth->authenticate($request);
            $this->auth->authorize($credential, 'tickets.read');
        } catch (ApiAuthException $e) {
            return ApiResponse::error($e->getMessage(), 'UNAUTHORIZED', 401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        return ApiResponse::success($this->tickets->paginate([], $page, $perPage));
    }

    /** POST /api/tickets/{id}/reply — add a reply to a ticket (write scope). */
    public function replyToTicket(Request $request, array $params): Response
    {
        try {
            $credential = $this->auth->authenticate($request);
            $this->auth->authorize($credential, 'tickets.write');
        } catch (ApiAuthException $e) {
            return ApiResponse::error($e->getMessage(), 'UNAUTHORIZED', 401);
        }

        $ticket = $this->tickets->find((int) $params['id']);

        if ($ticket === null) {
            return ApiResponse::error('Ticket not found.', 'NOT_FOUND', 404);
        }

        $message = trim((string) $request->input('message', ''));

        if ($message === '') {
            return ApiResponse::error('Message is required.', 'VALIDATION_ERROR', 422);
        }

        $replyId = $this->ticketService->reply(
            (int) $ticket['id'],
            'admin',
            null, // API acts on behalf of staff, not a specific admin account
            'API',
            $message
        );

        return ApiResponse::success(['reply_id' => $replyId], 201);
    }

    /** POST /api/invoices — create an invoice from line items (write scope). */
    public function createInvoice(Request $request): Response
    {
        try {
            $credential = $this->auth->authenticate($request);
            $this->auth->authorize($credential, 'invoices.write');
        } catch (ApiAuthException $e) {
            return ApiResponse::error($e->getMessage(), 'UNAUTHORIZED', 401);
        }

        $clientId = (int) $request->input('client_id', 0);
        $items = $request->input('items');

        if ($clientId <= 0 || !is_array($items) || $items === []) {
            return ApiResponse::error('client_id and items[] are required.', 'VALIDATION_ERROR', 422);
        }

        if ($this->clients->find($clientId) === null) {
            return ApiResponse::error('Client not found.', 'NOT_FOUND', 404);
        }

        $cleanItems = [];
        foreach ($items as $item) {
            $description = trim((string) ($item['description'] ?? ''));
            $amount = (float) ($item['amount'] ?? 0);

            if ($description !== '' && $amount > 0) {
                $cleanItems[] = ['description' => $description, 'amount' => $amount];
            }
        }

        if ($cleanItems === []) {
            return ApiResponse::error('At least one valid item is required.', 'VALIDATION_ERROR', 422);
        }

        $currency = \CodeVault\Support\App::container()->make(\CodeVault\Billing\CurrencyService::class);
        $client = $this->clients->find($clientId);
        $lock = $currency->denominateFor($client);

        $invoiceId = $this->invoices->createFromItems($clientId, $cleanItems, $lock['currency_id'], $lock['currency_rate']);

        return ApiResponse::success(['invoice_id' => $invoiceId], 201);
    }
}
