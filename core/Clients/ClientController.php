<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Activity\ActivityLogger;
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
        private readonly \CodeVault\Support\TicketReplyRepository $ticketReplies
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_VIEW)) {
            return $denied;
        }

        $search = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));

        return $this->render('clients.index', [
            'results' => $this->clients->paginate($search, $page),
            'search' => $search,
        ]);
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
        $billingPage = max(1, (int) $request->query('billing_page', 1));
        $billingPagination = $this->invoices->paginateForClient((int) $client['id'], $billingPage, 10);
        $currency = $this->currencyService->resolveForClient($client);

        return $this->render('clients.show', [
            'client' => $client,
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
            'tab' => in_array($tab, ['summary', 'profile', 'contacts', 'billing', 'log', 'message'], true) ? $tab : 'summary',
            'contacts' => $this->contacts->forClient((int) $client['id']),
            'activity' => $this->activity->forSubject('client', (int) $client['id']),
            'services' => $this->services->forClient((int) $client['id']),
            'invoices' => $billingPagination['data'],
            'billingPagination' => $billingPagination,
            'creditBalance' => $this->credit->balance((int) $client['id']),
            'creditLedger' => $this->credit->forClient((int) $client['id']),
            'departments' => $this->departments->all(),
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

        $this->mail->sendRaw($subject, nl2br(e($message)), $client['email'], $id);

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

        return [
            'client_group_id' => $groupId !== null && $groupId !== '' ? (int) $groupId : null,
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
        if ($admin !== null) {
            $this->session->set('original_admin_id', $admin['id']);
        }

        $this->session->set('client_id', $clientId);

        return Response::redirect('/client/dashboard');
    }

    public function delete(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission(PermissionRegistry::CLIENTS_MANAGE)) {
            return $denied;
        }

        $id = (int) $params['id'];
        $client = $this->clients->find($id);

        if ($client !== null) {
            $this->clients->delete($id);
            $admin = $this->guard->currentAdmin();
            $adminId = $admin ? (int) $admin['id'] : null;
            $this->activity->log('admin', $adminId, 'client.delete', 'client', $id, "Deleted client account #{$id} ({$client['first_name']} {$client['last_name']} <{$client['email']}>)");
        }

        return Response::redirect('/admin/clients?msg=' . urlencode('Client account deleted successfully.'));
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

        $deletedCount = $this->clients->bulkDelete($ids);
        $admin = $this->guard->currentAdmin();
        $adminId = $admin ? (int) $admin['id'] : null;
        $this->activity->log('admin', $adminId, 'client.bulk_delete', 'client', null, "Bulk deleted {$deletedCount} client account(s).");

        return Response::redirect('/admin/clients?msg=' . urlencode("Successfully deleted {$deletedCount} client account(s)."));
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
