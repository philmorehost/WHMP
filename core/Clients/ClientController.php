<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Ai\AiProvider;
use CodeVault\Ai\AiSettings;
use CodeVault\Ai\PiiRedactor;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\ClientCreditRepository;
use CodeVault\Billing\CreditService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\VatLookupService;
use CodeVault\Config;
use CodeVault\CustomFields\CustomFieldRepository;
use CodeVault\CustomFields\CustomFieldValueRepository;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\RecurringInvoiceRepository;
use CodeVault\Domains\DomainRepository;
use Throwable;

final class ClientController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ClientRepository $clients,
        private readonly ClientGroupRepository $groups,
        private readonly ClientContactRepository $contacts,
        private readonly ActivityLogger $activity,
        private readonly CustomFieldRepository $customFields,
        private readonly CustomFieldValueRepository $customFieldValues,
        private readonly EmailDispatcher $mail,
        private readonly Config $config,
        private readonly ServiceRepository $services,
        private readonly InvoiceRepository $invoices,
        private readonly ClientCreditRepository $credit,
        private readonly CreditService $creditService,
        private readonly VatLookupService $vatLookup,
        private readonly \CodeVault\Session\SessionManager $session,
        private readonly CurrencyService $currencyService,
        private readonly \CodeVault\Support\DepartmentRepository $departments,
        private readonly \CodeVault\Support\TicketRepository $tickets,
        private readonly \CodeVault\Support\TicketReplyRepository $ticketReplies,
        private readonly DomainRepository $domains,
        private readonly RecurringInvoiceRepository $recurringInvoices,
        private readonly \CodeVault\Billing\CurrencyRepository $currencies,
        // Replies from the Message tab go through TicketService so the ticket's
        // status/last_reply_at and its hooks behave exactly as they do when the
        // same reply is sent from the ticket page.
        private readonly \CodeVault\Support\TicketService $ticketService,
        private readonly AiProvider $aiProvider,
        private readonly AiSettings $aiSettings,
        // Appended last, as always here: this controller has ~26 dependencies and
        // is built by the container, but the rule is that a new one goes on the
        // end so no existing call site can silently rebind the rest.
        private readonly \CodeVault\Reseller\ResellerDomainSync $domainSync,
        // Store customers: signing in on the store's website, and the store's name on the
        // client pages. Nullable so nothing constructing this by hand has to change.
        private readonly ?\CodeVault\Clients\ClientImpersonation $impersonation = null,
        private readonly ?\CodeVault\Reseller\ResellerStoreRepository $stores = null
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_VIEW)) {
            return $denied;
        }

        $search = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));

        $filters = \CodeVault\Table\TableFilters::fromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['id' => true, 'name' => true, 'email' => true, 'company' => true, 'group' => true, 'status' => true, 'store' => true]
        );

        $sort = \CodeVault\Table\TableFilters::sortFromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['name' => 'c.last_name', 'email' => 'c.email', 'company' => 'c.company_name', 'group' => 'g.name', 'status' => 'c.status', 'joined' => 'c.created_at', 'store' => 'r.brand_name']
        );

        $results = $this->clients->paginate($search, $page, 20, $filters, $sort);

        $filterColumns = [
            ['filterable' => false],
            ['filterable' => true, 'key' => 'name', 'label' => 'Name / Email', 'type' => 'text', 'placeholder' => 'Name or email'],
            ['filterable' => true, 'key' => 'company', 'label' => 'Company', 'type' => 'text', 'placeholder' => 'Company name'],
            ['filterable' => true, 'key' => 'group', 'label' => 'Group', 'type' => 'text', 'placeholder' => 'Group name'],
            ['filterable' => true, 'key' => 'store', 'label' => 'Website', 'type' => 'select', 'options' => $this->storeFilterOptions()],
            ['filterable' => true, 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => [
                'active' => 'Active',
                'closed' => 'Closed',
                'inactive' => 'Inactive',
            ]],
            ['filterable' => false],
            ['filterable' => false],
            ['filterable' => false],
        ];

        // Live-search target: same search, same data, but only the results
        // table + pagination, no layout — the admin clients page fetches it
        // via XHR and swaps it into #admin-client-results, so typing never
        // reloads the page or loses focus.
        if ($request->query('fragment') === '1') {
            return Response::html($this->view->render('clients.index-results', [
                'results' => $results,
                'search' => $search,
                'filters' => $filters,
                'sort' => $sort,
                'filterColumns' => $filterColumns,
            ]));
        }

        return $this->render('clients.index', [
            'results' => $results,
            'search' => $search,
            'filters' => $filters,
            'sort' => $sort,
            'filterColumns' => $filterColumns,
        ]);
    }

    /**
     * Autocomplete endpoint for the admin "add order" client picker — returns
     * a small JSON list of id/name/email matches instead of a full page, so
     * the create-order form can filter a large client base as the admin
     * types without reloading. Kept separate from index() (which returns a
     * full paginated HTML page) and intentionally light (see
     * ClientRepository::search()).
     */
    public function options(Request $request): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_VIEW)) {
            return $denied;
        }

        $search = trim((string) $request->query('q', ''));
        $limit = max(1, min(100, (int) $request->query('limit', 20)));

        $json = json_encode([
            'clients' => $this->clients->search($search, $limit),
        ], JSON_UNESCAPED_UNICODE);

        return (new Response($json, 200))
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * CSV export of the client list (marketing use case: bulk email/phone
     * lists) — honors the same `q` search filter as index(), read-only so
     * gated on CLIENTS_VIEW rather than CLIENTS_MANAGE.
     */
    public function export(Request $request): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_VIEW)) {
            return $denied;
        }

        $search = trim((string) $request->query('q', ''));

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['ID', 'Email', 'First Name', 'Last Name', 'Company', 'Phone', 'Country', 'Status', 'Created At']);

        foreach ($this->clients->allForExport($search) as $client) {
            fputcsv($stream, [
                $client['id'],
                $client['email'],
                $client['first_name'],
                $client['last_name'],
                $client['company_name'] ?? '',
                $client['phone'] ?? '',
                $client['country'] ?? '',
                $client['status'],
                $client['created_at'],
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return (new Response($csv, 200))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="clients-export-' . date('Y-m-d') . '.csv"')
            ->withHeader('Content-Length', (string) strlen($csv));
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        return $this->render('clients.form', $this->formData(null, null));
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $fields = $this->extractFields($request);

        if ($fields['email'] === '' || $fields['first_name'] === '' || $fields['last_name'] === '') {
            return $this->render('clients.form', $this->formData(null, null, 'Email, first name, and last name are required.'));
        }

        if ($this->clients->findByEmail($fields['email']) !== null) {
            return $this->render('clients.form', $this->formData(null, null, 'A client with that email already exists.'));
        }

        $id = $this->clients->create($fields);
        $this->customFieldValues->saveForClient($id, $this->extractCustomFieldValues($request));
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'client.created', 'client', $id, "Created client {$fields['email']}", $request->ip());

        try {
            $this->mail->sendTemplate('client_welcome', $fields['email'], [
                'first_name' => $fields['first_name'],
                'email' => $fields['email'],
                'company_name' => brand_name(),
            ], $id);
        } catch (Throwable) {
            // Template missing/misconfigured shouldn't block client creation.
        }

        return Response::redirect("/admin/clients/{$id}");
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_VIEW)) {
            return $denied;
        }

        $client = $this->clients->find((int) $params['id']);

        if ($client === null) {
            return Response::html('404 Not Found', 404);
        }

        $tab = (string) $request->query('tab', 'summary');
        $tab = in_array($tab, ['summary', 'profile', 'contacts', 'billing', 'log', 'message'], true) ? $tab : 'summary';

        $billingPage = max(1, (int) $request->query('billing_page', 1));
        $billingPagination = $this->invoices->paginateForClient((int) $client['id'], $billingPage, 10);
        $currency = $this->currencyService->resolveForClient($client);

        // Message tab: every ticket this client has, plus the one the admin
        // opened to reply to (null when they haven't picked one).
        $clientTickets = $this->tickets->forClient((int) $client['id']);
        $activeTicket = $this->ticketForClient($client, (int) $request->query('ticket_id', 0));
        $activeTicketReplies = $activeTicket !== null
            ? $this->ticketReplies->forTicket((int) $activeTicket['id'], includePrivate: true)
            : [];

        // A drafted or refined reply is handed back through a one-shot session
        // value rather than the query string — the text is far too long for a
        // URL, and pullFlash() means a refresh can't resurface a stale draft.
        // Pulled only on the message tab so a visit to another tab can't eat it.
        $aiFlash = $tab === 'message' ? $this->session->pullFlash('client_message_ai', []) : [];
        $aiFlash = is_array($aiFlash) ? $aiFlash : [];

        return $this->render('clients.show', [
            'client' => $client,
            // The reseller store this customer belongs to (null: the platform's own).
            'store' => (int) ($client['reseller_id'] ?? 0) > 0 && $this->stores !== null
                ? $this->stores->find((int) $client['reseller_id'])
                : null,
            'currency' => $currency,
            // services.amount is written once at checkout (denominateFor() —
            // already in the client's own currency, no per-row rate to read
            // it back through) and never touched again, so it must be shown
            // raw, not passed through format()'s live rate: doing so
            // re-multiplies an already-denominated figure and was a
            // confirmed bug elsewhere in the app (see the client dashboard's
            // services widget) — a service invoiced correctly at ₦41,397.17
            // rendered as ₦62,095,750.00 once someone "fixed" this exact
            // spot the same way. Invoices DO lock a real currency_id/
            // currency_rate per row, so their total is read through that
            // lock via formatDocument() instead, which is the correct and
            // different case just below.
            'serviceMoney' => fn (float $amount): string => ($currency['symbol'] ?? '$') . number_format($amount, 2),
            'invoiceMoney' => fn (array $invoice): string => $this->currencyService->formatDocument(
                (float) $invoice['total'],
                $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null,
                (float) ($invoice['currency_rate'] ?? 1.0),
                $currency
            ),
            'tab' => $tab,
            'contacts' => $this->contacts->forClient((int) $client['id']),
            'activity' => $this->activity->forSubject('client', (int) $client['id']),
            'services' => $this->services->forClient((int) $client['id']),
            'domains' => $this->domains->forClient((int) $client['id']),
            'recurringInvoices' => $this->recurringInvoices->forClient((int) $client['id']),
            'invoices' => $billingPagination['data'],
            'billingPagination' => $billingPagination,
            'creditBalance' => $this->credit->balance((int) $client['id']),
            'creditLedger' => $this->credit->forClient((int) $client['id']),
            'departments' => $this->departments->all(),
            // Message tab — the client's tickets, the one being replied to, and
            // any AI draft/refine result on its way back to the reply box.
            'tickets' => $clientTickets,
            'activeTicket' => $activeTicket,
            'activeTicketReplies' => $activeTicketReplies,
            'aiSuggestion' => $aiFlash['text'] ?? null,
            'aiError' => $aiFlash['error'] ?? null,
            'aiMode' => $aiFlash['mode'] ?? null,
            'msg' => (string) $request->query('msg', ''),
            'error' => (string) $request->query('error', ''),
        ]);
    }

    public function sendMessage(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $id = (int) $params['id'];
        $client = $this->clients->find($id);
        if ($client === null) {
            return Response::html('404 Not Found', 404);
        }

        $subject = trim((string) $request->input('subject', ''));
        $message = trim((string) $request->input('message', ''));

        if ($subject === '' || $message === '') {
            return Response::redirect("/admin/clients/{$id}?tab=message&error=" . urlencode('Subject and message body are required.'));
        }

        // The body is handed over UNCHANGED. sendRaw() converts plain text itself
        // (wrapInModernLayout -> FormattedText::toHtml), so pre-converting it here
        // converted it twice and the client received a literal `<br />` at the end of
        // every line — the same defect as the admin cancellation report. See
        // tests/Unit/EmailBodyRenderingTest.php.
        $this->mail->sendRaw($subject, $message, $client['email'], $id);

        $admin = $this->guard->currentAdmin();
        $adminId = $admin ? (int) $admin['id'] : null;
        $this->activity->log('admin', $adminId, 'client.message_sent', 'client', $id, "Sent direct email message: {$subject}");

        return Response::redirect("/admin/clients/{$id}?tab=message&msg=" . urlencode('Direct email message sent successfully to ' . $client['email']));
    }

    public function createTicket(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $id = (int) $params['id'];
        $client = $this->clients->find($id);
        if ($client === null) {
            return Response::html('404 Not Found', 404);
        }

        $departmentId = (int) $request->input('department_id', 1);
        $subject = trim((string) $request->input('subject', ''));
        $message = trim((string) $request->input('message', ''));
        $priority = (string) $request->input('priority', 'medium');

        if ($subject === '' || $message === '') {
            return Response::redirect("/admin/clients/{$id}?tab=message&error=" . urlencode('Subject and message body are required to open a support ticket.'));
        }

        $ticketId = $this->tickets->create([
            'client_id' => $id,
            'email' => $client['email'],
            'department_id' => $departmentId,
            'subject' => $subject,
            'status' => 'open',
            'priority' => in_array($priority, ['low', 'medium', 'high'], true) ? $priority : 'medium',
        ]);

        $admin = $this->guard->currentAdmin();
        $adminId = $admin ? (int) $admin['id'] : null;
        $adminName = $admin ? trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? '')) : 'Support Staff';

        $this->ticketReplies->create($ticketId, 'admin', $adminId, $adminName ?: 'Support Staff', $message, false);

        // Notify client via email
        $ticketEmailSubject = "[Ticket #{$ticketId}] {$subject}";
        $ticketEmailBody = "<p>Dear " . e($client['first_name']) . ",</p><p>A support ticket has been opened on your behalf:</p><hr><p><strong>Subject:</strong> " . e($subject) . "</p><p>" . nl2br(e($message)) . "</p><hr><p>You can reply directly to this ticket in your client portal.</p>";
        $this->mail->sendRaw($ticketEmailSubject, $ticketEmailBody, $client['email'], $id);

        $this->activity->log('admin', $adminId, 'ticket.created_for_client', 'client', $id, "Opened support ticket #{$ticketId}: {$subject}");

        return Response::redirect("/admin/clients/{$id}?tab=message&msg=" . urlencode("Support ticket #{$ticketId} created successfully for client."));
    }

    /**
     * Replies to one of this client's tickets straight from their Message tab,
     * so support does not have to jump to the ticket page to answer.
     *
     * Both ids arrive in the URL, so the ticket is re-checked against the
     * client before anything is written: without that, a mistyped ticket_id —
     * or a crafted link — would post a reply onto a different client's ticket.
     *
     * The write goes through TicketService::reply() rather than straight to the
     * reply table, because that is the single place a ticket's status moves:
     * it marks the ticket "answered", stamps last_reply_at and fires the reply
     * hooks, exactly as a reply sent from the ticket page does.
     */
    public function replyToTicket(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $client = $this->clients->find((int) $params['id']);
        $ticket = $this->ticketForClient($client, (int) $params['ticketId']);

        if ($client === null || $ticket === null) {
            return Response::html('404 Not Found', 404);
        }

        $message = trim((string) $request->input('message', ''));

        if ($message === '') {
            return Response::redirect($this->messageTabUrl((int) $client['id'], (int) $ticket['id'], 'error=' . urlencode('A reply cannot be empty.')));
        }

        $admin = $this->guard->currentAdmin();
        $adminId = $admin ? (int) $admin['id'] : null;
        $adminName = $admin ? trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? '')) : '';

        $this->ticketService->reply((int) $ticket['id'], 'admin', $adminId, $adminName !== '' ? $adminName : 'Support Staff', $message);

        $this->activity->log('admin', $adminId, 'ticket.replied', 'ticket', (int) $ticket['id'], "Replied to ticket #{$ticket['id']} from client #{$client['id']}'s Message tab", $request->ip());

        return Response::redirect($this->messageTabUrl((int) $client['id'], (int) $ticket['id'], 'msg=' . urlencode("Reply sent on ticket #{$ticket['id']}.")));
    }

    /**
     * Asks the AI provider to draft a reply for this client's ticket, from the
     * conversation so far. The result is handed back to the reply box rather
     * than sent — the admin still reads, edits and sends it.
     */
    public function aiDraftTicketReply(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $client = $this->clients->find((int) $params['id']);
        $ticket = $this->ticketForClient($client, (int) $params['ticketId']);

        if ($client === null || $ticket === null) {
            return Response::html('404 Not Found', 404);
        }

        $clientId = (int) $client['id'];
        $ticketId = (int) $ticket['id'];
        $error = $this->aiUnavailableReason();

        if ($error !== null) {
            return $this->flashAiResult($clientId, $ticketId, null, $error, 'draft');
        }

        $conversation = array_map(
            static fn (array $reply): array => ['author' => (string) $reply['author_type'], 'message' => (string) $reply['message']],
            $this->ticketReplies->forTicket($ticketId, includePrivate: false)
        );

        $result = $this->aiProvider->complete(...$this->buildTicketReplyPrompts($conversation));

        return $this->flashAiResult(
            $clientId,
            $ticketId,
            $result['success'] ? (string) $result['text'] : null,
            $result['success'] ? null : (string) ($result['error'] ?? 'The AI provider did not return a reply.'),
            'draft'
        );
    }

    /**
     * Rewrites the draft the admin has already typed, instead of inventing a
     * reply from the transcript — "AI refine message" as opposed to "generate
     * response". The prompt is explicit that it must keep every fact the draft
     * contains and invent none, since this text is sent to a customer.
     */
    public function aiRefineTicketReply(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $client = $this->clients->find((int) $params['id']);
        $ticket = $this->ticketForClient($client, (int) $params['ticketId']);

        if ($client === null || $ticket === null) {
            return Response::html('404 Not Found', 404);
        }

        $clientId = (int) $client['id'];
        $ticketId = (int) $ticket['id'];
        // The refine button re-posts the reply textarea itself (a formaction on
        // a button inside the reply form), so the text arrives as `message`;
        // `draft` is accepted as well for direct callers.
        $draft = trim((string) ($request->input('draft') ?? $request->input('message', '')));

        if ($draft === '') {
            return $this->flashAiResult($clientId, $ticketId, null, 'Type a reply first — there is nothing to refine.', 'refine');
        }

        $error = $this->aiUnavailableReason();

        if ($error !== null) {
            return $this->flashAiResult($clientId, $ticketId, null, $error, 'refine');
        }

        $systemPrompt = 'You are a support agent assistant for a web hosting and domain company. '
            . 'Rewrite the support agent\'s draft reply below so it is clear, professional and concise, '
            . 'in the same language as the draft. Keep every fact, figure and promise it contains and add none of your own. '
            . 'Reply with only the rewritten message body — no greeting, no signature, no commentary.';

        $result = $this->aiProvider->complete($systemPrompt, PiiRedactor::redact($draft));

        return $this->flashAiResult(
            $clientId,
            $ticketId,
            $result['success'] ? (string) $result['text'] : null,
            $result['success'] ? null : (string) ($result['error'] ?? 'The AI provider did not return a rewrite.'),
            'refine'
        );
    }

    /**
     * Why AI drafting can't run right now, or null when it can. Mirrors the
     * ticket page's gate and its wording, so the same setting explains the
     * same refusal on both screens.
     */
    private function aiUnavailableReason(): ?string
    {
        if (!$this->aiSettings->isFeatureEnabled('ticket_replies')) {
            return 'AI ticket-reply drafting is turned off. An admin can enable it under Configuration → AI Copilot.';
        }

        return null;
    }

    /**
     * Hands a draft/refine result back to the reply box via a one-shot session
     * value and redirects, so the POST is refresh-safe (a refresh re-renders
     * the form rather than re-calling the provider and re-billing the request).
     */
    private function flashAiResult(int $clientId, int $ticketId, ?string $text, ?string $error, string $mode): Response
    {
        $this->session->flash('client_message_ai', [
            'ticket_id' => $ticketId,
            'mode' => $mode,
            'text' => $text,
            'error' => $error,
        ]);

        return Response::redirect($this->messageTabUrl($clientId, $ticketId, ''));
    }

    // A ticket is only ever acted on through its owning client: the id in the
    // URL is not evidence of ownership, so every Message-tab action re-checks it.
    /** @param array<string, mixed>|null $client */
    private function ticketForClient(?array $client, int $ticketId): ?array
    {
        if ($client === null || $ticketId <= 0) {
            return null;
        }

        $ticket = $this->tickets->find($ticketId);

        return $ticket !== null && (int) $ticket['client_id'] === (int) $client['id'] ? $ticket : null;
    }

    private function messageTabUrl(int $clientId, int $ticketId, string $flash): string
    {
        $url = "/admin/clients/{$clientId}?tab=message&ticket_id={$ticketId}";

        return $flash !== '' ? $url . '&' . $flash : $url;
    }

    /**
     * @param array<int, array{author: string, message: string}> $conversation oldest-first
     * @return array{0: string, 1: string} [systemPrompt, userPrompt]
     */
    private function buildTicketReplyPrompts(array $conversation): array
    {
        $systemPrompt = 'You are a support agent assistant for a web hosting and domain company. '
            . 'Draft a concise, professional reply to the customer\'s latest message in the ticket transcript below. '
            . 'Reply with only the message body — no greeting, no signature, no explanation of what you did.';

        $transcript = [];

        foreach ($conversation as $turn) {
            // A store's reply is support, not the customer — see
            // TicketService::isSupportAuthor(). Labelling it 'Customer' would have
            // the AI draft a reply as though the customer had just written it.
            // Fully qualified on purpose: this file does not import TicketService
            // (its constructor names it in full), and an un-imported class name in a
            // static call is silently resolved against THIS namespace — a fatal that
            // no lint catches.
            $speaker = \CodeVault\Support\TicketService::isSupportAuthor((string) $turn['author']) ? 'Support' : 'Customer';
            $transcript[] = "{$speaker}: " . PiiRedactor::redact($turn['message']);
        }

        return [$systemPrompt, implode("\n\n", $transcript)];
    }

    public function grantCredit(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $clientId = (int) $params['id'];
        $client = $this->clients->find($clientId);
        if ($client === null) {
            return Response::html('404 Not Found', 404);
        }

        $amount = (float) $request->input('amount', 0);
        $action = $request->input('action', 'credit');
        $reason = trim((string) $request->input('reason', ''));

        if ($amount > 0) {
            $currency = $this->currencyService->resolveForClient($client);
            $currencySymbol = $currency['symbol'] ?? '$';
            $adminId = (int) $this->guard->currentAdmin()['id'];
            if ($action === 'debit') {
                $reason = $reason ?: 'Manual credit debit';
                $this->creditService->debit($clientId, $amount, $reason, $adminId);
                $this->activity->log('admin', $adminId, 'client.credit_debited', 'client', $clientId, "Debited {$currencySymbol}" . number_format($amount, 2) . " credit: {$reason}", $request->ip());
            } else {
                $reason = $reason ?: 'Manual credit grant';
                $this->creditService->grant($clientId, $amount, $reason, $adminId);
                $this->activity->log('admin', $adminId, 'client.credit_granted', 'client', $clientId, "Granted {$currencySymbol}" . number_format($amount, 2) . " credit: {$reason}", $request->ip());
            }
        }

        return Response::redirect("/admin/clients/{$clientId}?tab=billing");
    }

    public function editForm(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $client = $this->clients->find((int) $params['id']);

        if ($client === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('clients.form', $this->formData($client, (int) $client['id']));
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $id = (int) $params['id'];
        $fields = $this->extractFields($request);

        // R30 gave the client self-service path this same check
        // (ClientAccountController::updateProfile()) but left the admin
        // path's matching gap alone as out of scope; closing it here now —
        // a stale "VIES verified" badge must not survive the number it
        // was verified against being edited to something else.
        $existing = $this->clients->find($id);
        if ($existing !== null && $fields['vat_number'] !== $existing['vat_number']) {
            $this->clients->clearVatVerification($id);
        }

        $this->clients->update($id, $fields);
        if (isset($fields['password']) && $fields['password'] !== '') {
            $this->clients->updatePassword($id, $fields['password']);
        }

        // Currency is deliberately NOT part of update(): switching it has to
        // recalculate every amount the client is billed — services, domains,
        // invoices, orders, quotes, recurring-invoice templates, unbilled
        // charges and the payments that settled an invoice — because on this
        // install all of those are denominated in the client's own currency.
        // That is what updateCurrency() does, and it no-ops when the currency
        // is unchanged, so re-saving an unrelated edit can't re-round the
        // account. A blank choice means "leave the currency alone" rather than
        // reverting to the system default.
        //
        // Whether settled records move too is the admin's call on each save
        // rather than a stored preference: only they can weigh a uniformly
        // denominated record against restating a figure a gateway already
        // charged.
        $requestedCurrency = $request->input('currency_id');
        if ($requestedCurrency !== null && $requestedCurrency !== '') {
            $newCurrencyId = (int) $requestedCurrency;
            $oldCurrencyId = $existing !== null && $existing['currency_id'] !== null ? (int) $existing['currency_id'] : null;
            $includeSettled = (string) $request->input('include_settled', '') === '1';

            if ($newCurrencyId !== $oldCurrencyId && ($currency = $this->currencies->find($newCurrencyId)) !== null) {
                $this->clients->updateCurrency($id, $newCurrencyId, $includeSettled);
                $this->activity->log(
                    'admin',
                    (int) $this->guard->currentAdmin()['id'],
                    'client.currency_changed',
                    'client',
                    $id,
                    "Changed client #{$id} default currency to " . (string) ($currency['code'] ?? $newCurrencyId)
                        . ($includeSettled ? ' — every balance recalculated, settled records included' : ' — live balances recalculated'),
                    $request->ip()
                );
            }
        }
        $this->customFieldValues->saveForClient($id, $this->extractCustomFieldValues($request));
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'client.updated', 'client', $id, "Updated client #{$id}", $request->ip());

        return Response::redirect("/admin/clients/{$id}?tab=profile");
    }

    public function verifyVat(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $id = (int) $params['id'];
        $client = $this->clients->find($id);

        if ($client === null) {
            return Response::html('404 Not Found', 404);
        }

        $country = trim((string) ($client['country'] ?? ''));
        $vatNumber = trim((string) ($client['vat_number'] ?? ''));

        if ($country !== '' && $vatNumber !== '') {
            $result = $this->vatLookup->lookup($country, $vatNumber);

            if ($result['checked']) {
                $this->clients->recordVatVerification($id, $result['valid'], $result['name']);
                $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'client.vat_verified', 'client', $id, "VIES VAT check for client #{$id}: " . ($result['valid'] ? 'valid' : 'invalid'), $request->ip());
            }
        }

        return Response::redirect("/admin/clients/{$id}?tab=profile");
    }

    public function close(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $id = (int) $params['id'];
        $this->clients->close($id);
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'client.closed', 'client', $id, "Closed client #{$id}", $request->ip());

        return Response::redirect("/admin/clients/{$id}");
    }

    public function addContact(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $clientId = (int) $params['id'];
        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', ''));

        if ($name !== '' && $email !== '') {
            $this->contacts->create($clientId, $name, $email);
        }

        return Response::redirect("/admin/clients/{$clientId}?tab=contacts");
    }

    public function removeContact(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $this->contacts->delete((int) $params['contactId']);

        return Response::redirect("/admin/clients/{$params['id']}?tab=contacts");
    }

    /** @return array<string, mixed> */
    private function extractFields(Request $request): array
    {
        $groupId = $request->input('client_group_id');
        $currencyId = $request->input('currency_id');

        return [
            'client_group_id' => $groupId !== null && $groupId !== '' ? (int) $groupId : null,
            // '' / null = "system default" — create() falls back to the default
            // currency for it, and update() leaves the existing one untouched.
            'currency_id' => $currencyId !== null && $currencyId !== '' ? (int) $currencyId : null,
            'email' => trim((string) $request->input('email', '')),
            'password' => (string) $request->input('password', ''),
            'first_name' => trim((string) $request->input('first_name', '')),
            'last_name' => trim((string) $request->input('last_name', '')),
            'company_name' => trim((string) $request->input('company_name', '')) ?: null,
            'address1' => trim((string) $request->input('address1', '')) ?: null,
            'address2' => trim((string) $request->input('address2', '')) ?: null,
            'city' => trim((string) $request->input('city', '')) ?: null,
            'state' => trim((string) $request->input('state', '')) ?: null,
            'postcode' => trim((string) $request->input('postcode', '')) ?: null,
            'country' => trim((string) $request->input('country', '')) ?: null,
            'vat_number' => trim((string) $request->input('vat_number', '')) ?: null,
            'phone' => trim((string) $request->input('phone', '')) ?: null,
            'status' => (string) $request->input('status', 'active'),
            'notes' => trim((string) $request->input('notes', '')) ?: null,
        ];
    }

    /** @return array<int, string> custom_field_id => submitted value */
    private function extractCustomFieldValues(Request $request): array
    {
        $submitted = (array) $request->input('custom_fields', []);
        $values = [];

        foreach ($submitted as $fieldId => $value) {
            $values[(int) $fieldId] = trim((string) $value);
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function formData(?array $client, ?int $clientId, ?string $error = null): array
    {
        return [
            'client' => $client,
            'groups' => $this->groups->all(),
            'currencies' => $this->currencies->all(),
            'customFields' => $this->customFields->forType('client'),
            'customFieldValues' => $clientId !== null ? $this->customFieldValues->forClient($clientId) : [],
            'error' => $error,
        ];
    }

    public function loginAsClient(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $clientId = (int) $params['id'];
        $client = $this->clients->find($clientId);

        if ($client === null) {
            return Response::redirect('/admin/clients');
        }

        $admin = $this->guard->currentAdmin();

        // A reseller store's customer lives on the store's website: they sign in there,
        // see the store's brand and prices there, and their session cookie is for that
        // host only. Switching the session HERE would put the admin in the platform's own
        // client area as that customer — a place the customer never sees, with the
        // platform's prices and branding. So the admin is sent to the store's site with a
        // one-time ticket instead (ClientImpersonation), and their own admin session on
        // this host is left exactly as it was, ready for "Return to Admin Panel".
        if ((int) ($client['reseller_id'] ?? 0) > 0 && $this->impersonation !== null) {
            $result = $this->impersonation->issue(
                $client,
                'admin',
                (int) ($admin['id'] ?? 0),
                trim((string) ($admin['display_name'] ?? $admin['username'] ?? 'Admin')) . ' (admin)',
                rtrim((string) $this->config->env('APP_URL', ''), '/') . '/admin/clients/' . $clientId,
                $request->ip()
            );

            if (!$result['success'] || $result['url'] === null) {
                return Response::redirect('/admin/clients/' . $clientId . '?error=' . rawurlencode((string) $result['error']));
            }

            $this->activity->log(
                'admin',
                $admin !== null ? (int) $admin['id'] : null,
                'client.login_as_store_customer',
                'client',
                $clientId,
                'Signed in as this customer on store #' . (int) $client['reseller_id'] . "'s website",
                $request->ip()
            );

            return Response::redirect((string) $result['url']);
        }

        if ($admin !== null) {
            $this->session->set('original_admin_id', $admin['id']);
        }

        $this->session->set('client_id', $clientId);

        return Response::redirect('/client/dashboard');
    }

    /**
     * Options for the clients list's "Website" filter: the platform's own customers,
     * every store's customers, then each store by name.
     *
     * @return array<string, string>
     */
    private function storeFilterOptions(): array
    {
        $options = ['direct' => 'Direct (this website)', 'stores' => 'Any reseller store'];

        if ($this->stores === null) {
            return $options;
        }

        foreach ($this->stores->all() as $store) {
            $name = trim((string) ($store['brand_name'] ?? ''));
            $options[(string) (int) $store['id']] = ($name !== '' ? $name : (string) $store['slug']) . ' (store #' . (int) $store['id'] . ')';
        }

        return $options;
    }

    /**
     * Delete an account, taking anything it had on the hosting panel with it.
     *
     * THE PANEL STEP RUNS FIRST, and that ordering is not a preference.
     * `resellers.client_id` is `ON DELETE CASCADE`, so the moment the client row
     * goes, the store row holding `custom_domain` and `domain_provisioned_host`
     * goes with it — and with it the only record of which hostname we added to the
     * server. After the delete there is nothing left to remove, so the addon
     * domain would sit there answering for a hostname no store claims, which
     * resolves to our own shop at our own prices.
     */
    public function delete(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $id = (int) $params['id'];
        $client = $this->clients->find($id);
        $notice = '';

        if ($client !== null) {
            $outcome = $this->domainSync->removeForClient($id);

            // Only worth saying when something actually came off, or when it did
            // not and somebody has to finish the job by hand.
            $notice = $outcome['changed'] || !$outcome['ok'] ? (string) $outcome['message'] : '';

            $this->clients->delete($id);
            $admin = $this->guard->currentAdmin();
            $adminId = $admin ? (int) $admin['id'] : null;
            $this->activity->log('admin', $adminId, 'client.delete', 'client', $id, "Deleted client account #{$id} ({$client['first_name']} {$client['last_name']} <{$client['email']}>)" . ($notice !== '' ? ' — ' . $notice : ''));
        }

        return Response::redirect('/admin/clients?msg=' . urlencode(
            'Client account deleted successfully.' . ($notice !== '' ? ' ' . $notice : '')
        ));
    }

    public function bulkDelete(Request $request): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $ids = array_filter(
            array_map('intval', (array) $request->input('client_ids', [])),
            fn($id) => $id > 0
        );

        if (empty($ids)) {
            return Response::redirect('/admin/clients?msg=' . urlencode('No client accounts were selected for deletion.'));
        }

        // Same ordering as delete(), for the same reason: the store rows are about
        // to cascade away, so every hostname we put on the server has to be read
        // and removed while it still exists.
        $removedCount = 0;
        $problems = [];

        foreach ($ids as $id) {
            $outcome = $this->domainSync->removeForClient((int) $id);

            if (!$outcome['ok']) {
                $problems[] = (string) $outcome['message'];
            } elseif (!empty($outcome['changed'])) {
                $removedCount++;
            }
        }

        $deletedCount = $this->clients->bulkDelete($ids);
        $admin = $this->guard->currentAdmin();
        $adminId = $admin ? (int) $admin['id'] : null;
        $this->activity->log(
            'admin',
            $adminId,
            'client.bulk_delete',
            'client',
            null,
            "Bulk deleted {$deletedCount} client account(s)."
                . ($removedCount > 0 ? " {$removedCount} store domain(s) removed from the hosting panel." : '')
                . ($problems !== [] ? ' Outstanding: ' . implode(' ', $problems) : '')
        );

        return Response::redirect('/admin/clients?msg=' . urlencode(
            "Successfully deleted {$deletedCount} client account(s)."
                . ($removedCount > 0 ? " {$removedCount} store domain(s) were taken off the hosting panel." : '')
                // Name the first problem on screen rather than only in the log: a
                // hostname left on the server is the part somebody has to act on,
                // and it is invisible until then.
                . ($problems !== []
                    ? ' ' . $problems[0] . (count($problems) > 1 ? ' (' . (count($problems) - 1) . ' more — see the activity log.)' : '')
                    : '')
        ));
    }

    private function requirePermission(string $permissionKey): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can($permissionKey)) {
            return Response::html("403 Forbidden — missing {$permissionKey} permission", 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Clients',
            'content' => $content,
        ]));
    }
}
