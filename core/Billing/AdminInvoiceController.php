<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Pdf\InvoicePdfBuilder;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class AdminInvoiceController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly InvoiceRepository $invoices,
        private readonly TransactionRepository $transactions,
        private readonly PaymentService $payments,
        private readonly ActivityLogger $activity,
        private readonly ClientRepository $clients,
        private readonly InvoicePdfBuilder $pdf,
        private readonly RefundService $refunds,
        private readonly CurrencyService $currency,
        private readonly SettingsRepository $settings,
        private readonly CurrencyRepository $currencies,
        private readonly InvoiceReminderService $reminders
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->query('status', '');
        $page = max(1, (int) $request->query('page', 1));

        $container = \CodeVault\Support\App::container();
        $db = $container->make(\CodeVault\Database::class);

        // NULLIF guards a corrupt/zero exchange rate. COALESCE alone only
        // substitutes for NULL, so a rate of 0 sailed through and multiplied
        // the invoice to nothing — the row still listed its amount while the
        // summary above silently omitted it, so the totals never reconciled.
        // Same currency-locking rule as InvoiceRepository::paginate(): a
        // NULL currency_id is the base currency, full stop — never the
        // client's current preference — and the per-row rate has to be
        // applied inside each SUM(), since i.total is stored unconverted.
        $currencyStats = $db->select(
            "SELECT
                curr.code AS currency_code,
                curr.symbol AS currency_symbol,
                SUM(CASE WHEN i.status = 'paid' THEN i.total * COALESCE(NULLIF(i.currency_rate, 0), 1) ELSE 0 END) AS total_paid,
                SUM(CASE WHEN i.status = 'unpaid' THEN i.total * COALESCE(NULLIF(i.currency_rate, 0), 1) ELSE 0 END) AS total_unpaid,
                SUM(CASE WHEN i.status = 'unpaid' AND i.due_date < ? THEN i.total * COALESCE(NULLIF(i.currency_rate, 0), 1) ELSE 0 END) AS total_overdue
             FROM invoices i
             JOIN clients c ON c.id = i.client_id
             LEFT JOIN currencies curr ON curr.id = COALESCE(i.currency_id, c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
             GROUP BY curr.id",
             [(new \DateTimeImmutable())->format('Y-m-d')]
        );

        $filters = \CodeVault\Table\TableFilters::fromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['id' => true, 'client' => true, 'total' => true, 'due_date' => true, 'status' => true]
        );

        $sort = \CodeVault\Table\TableFilters::sortFromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['id' => 'i.id', 'client' => 'c.last_name', 'total' => 'i.total', 'due_date' => 'i.due_date', 'status' => 'i.status']
        );

        return $this->render('billing.invoices-index', [
            'results' => $this->invoices->paginate($status !== '' ? $status : null, $page, 20, $filters, $sort),
            'statusFilter' => $status,
            'filters' => $filters,
            'sort' => $sort,
            'filterColumns' => [
                ['filterable' => false],
                ['filterable' => true, 'key' => 'id', 'label' => 'Invoice #', 'type' => 'number', 'placeholder' => 'e.g. 3545'],
                ['filterable' => true, 'key' => 'client', 'label' => 'Client', 'type' => 'text', 'placeholder' => 'Name or email'],
                ['filterable' => true, 'key' => 'total', 'label' => 'Total', 'type' => 'number', 'placeholder' => 'e.g. 19.99'],
                ['filterable' => true, 'key' => 'due_date', 'label' => 'Due Date', 'type' => 'text', 'placeholder' => 'YYYY-MM-DD'],
                ['filterable' => true, 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => [
                    'unpaid' => 'Unpaid',
                    'paid' => 'Paid',
                    'cancelled' => 'Cancelled',
                ]],
                ['filterable' => false],
            ],
            'currencyStats' => $currencyStats,
            'cancelledCount' => $request->query('cancelled') !== null ? max(0, (int) $request->query('cancelled')) : null,
            'skippedCount' => max(0, (int) $request->query('skipped', 0)),
            'zeroValueCount' => $this->invoices->countZeroValueUnpaid(),
            'zeroPaidCount' => $request->query('zero_paid') !== null ? max(0, (int) $request->query('zero_paid')) : null,
            'remindedCount' => $request->query('reminded') !== null ? max(0, (int) $request->query('reminded')) : null,
            'reminderSkipped' => max(0, (int) $request->query('reminder_skipped', 0)),
        ]);
    }

    /** Blank manual-invoice form. */
    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('billing.invoice-create', [
            'clients' => $this->clients->all(),
            'error' => null,
            'old' => ['client_id' => '', 'due_in_days' => 7, 'items' => []],
        ]);
    }

    /**
     * Creates an invoice by hand — the ad-hoc charge an admin needs when the
     * automated paths (orders, renewals, billable items) don't apply.
     *
     * Currency is locked from the client at creation, like every other
     * invoice-generating path, so it can never render under the wrong symbol
     * later.
     */
    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $clientId = (int) $request->input('client_id', 0);
        $dueInDays = max(0, (int) $request->input('due_in_days', 7));

        $descriptions = (array) $request->input('item_description', []);
        $amounts = (array) $request->input('item_amount', []);

        $items = [];

        foreach ($descriptions as $index => $description) {
            $description = trim((string) $description);
            $rawAmount = trim((string) ($amounts[$index] ?? ''));

            // Skip blank rows so the admin can leave spare line inputs empty.
            if ($description === '' && $rawAmount === '') {
                continue;
            }

            if ($description === '' || !is_numeric($rawAmount)) {
                return $this->createError($request, 'Every line item needs a description and a numeric amount.');
            }

            $items[] = ['description' => $description, 'amount' => round((float) $rawAmount, 2)];
        }

        $client = $clientId > 0 ? $this->clients->find($clientId) : null;

        if ($client === null) {
            return $this->createError($request, 'Select a client for this invoice.');
        }

        if ($items === []) {
            return $this->createError($request, 'Add at least one line item.');
        }

        // The admin types the amount the client will actually be charged, in
        // that client's own currency — so the invoice is denominated in it at
        // rate 1.0 rather than treated as a base amount awaiting conversion.
        //
        // Using lockedColumnsFor() here would store the client's live rate and
        // the display layer would then multiply by it: an admin typing 25,000
        // for a client billed at 1500 would raise an invoice for 37,500,000.
        // Rate 1.0 means "already denominated in this currency", which is also
        // how every imported invoice in this database is stored.
        $clientCurrency = $this->currency->resolveForClient($client);
        $defaultCurrency = $this->currencies->default();
        $currencyId = (int) $clientCurrency['id'] === (int) $defaultCurrency['id'] ? null : (int) $clientCurrency['id'];

        $invoiceId = $this->invoices->createFromItems(
            $clientId,
            $items,
            $currencyId,
            1.0,
            null,
            $dueInDays
        );

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'invoice.created',
            'invoice',
            $invoiceId,
            'Manually created invoice #' . $invoiceId . ' for client #' . $clientId,
            $request->ip()
        );

        return Response::redirect("/admin/invoices/{$invoiceId}");
    }

    /**
     * Edits an invoice's line items and due date.
     *
     * Restricted to unpaid invoices. A paid invoice has a transaction recorded
     * against it, so changing its amount would leave the ledger disagreeing
     * with the invoice — the correct instrument for altering a settled invoice
     * is a credit note, which this platform already has.
     */
    public function updateItems(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $invoice = $this->invoices->find($id);

        if ($invoice === null) {
            return Response::html('404 Not Found', 404);
        }

        if (($invoice['status'] ?? '') !== 'unpaid') {
            return Response::redirect("/admin/invoices/{$id}?edit_error=" . urlencode('Only unpaid invoices can be edited. Issue a credit note to adjust a settled invoice.'));
        }

        $descriptions = (array) $request->input('item_description', []);
        $amounts = (array) $request->input('item_amount', []);

        $items = [];

        foreach ($descriptions as $index => $description) {
            $description = trim((string) $description);
            $rawAmount = trim((string) ($amounts[$index] ?? ''));

            // Blank rows are the admin's spare inputs, not an error.
            if ($description === '' && $rawAmount === '') {
                continue;
            }

            if ($description === '' || !is_numeric($rawAmount)) {
                return Response::redirect("/admin/invoices/{$id}?edit_error=" . urlencode('Every line needs a description and a numeric amount.'));
            }

            $items[] = ['description' => $description, 'amount' => round((float) $rawAmount, 2)];
        }

        if ($items === []) {
            return Response::redirect("/admin/invoices/{$id}?edit_error=" . urlencode('An invoice needs at least one line item.'));
        }

        $dueDate = trim((string) $request->input('due_date', ''));

        $this->invoices->replaceItems($id, $items, $dueDate !== '' ? $dueDate : null);

        $updated = $this->invoices->find($id);

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'invoice.edited',
            'invoice',
            $id,
            sprintf(
                'Edited invoice #%d: %d line item(s), total %s -> %s',
                $id,
                count($items),
                number_format((float) $invoice['total'], 2),
                number_format((float) ($updated['total'] ?? 0), 2)
            ),
            $request->ip()
        );

        return Response::redirect("/admin/invoices/{$id}?edited=1");
    }

    /** Emails a payment reminder for one invoice. */
    public function sendReminder(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $result = $this->reminders->send($id);

        if ($result['sent']) {
            $this->activity->log(
                'admin',
                (int) $this->guard->currentAdmin()['id'],
                'invoice.reminder_sent',
                'invoice',
                $id,
                "Sent payment reminder for invoice #{$id}",
                $request->ip()
            );
        }

        $flag = $result['sent'] ? 'reminder=1' : 'reminder_error=' . urlencode((string) ($result['reason'] ?? 'failed'));

        return Response::redirect("/admin/invoices/{$id}?{$flag}");
    }

    /**
     * Emails reminders for every selected invoice.
     *
     * Uses the same checkbox selection as bulk cancel. Invoices that are no
     * longer unpaid, or whose client has no email address, are skipped and
     * counted rather than aborting the run — one bad row shouldn't stop the
     * other nineteen reminders going out.
     */
    public function bulkSendReminders(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ids = array_map('intval', (array) $request->input('invoice_ids', []));
        $result = $this->reminders->sendMany($ids);

        if ($result['sent'] > 0) {
            $this->activity->log(
                'admin',
                (int) $this->guard->currentAdmin()['id'],
                'invoice.bulk_reminders_sent',
                'invoice',
                null,
                "Sent {$result['sent']} payment reminder(s)",
                $request->ip()
            );
        }

        return Response::redirect('/admin/invoices?reminded=' . $result['sent'] . '&reminder_skipped=' . $result['skipped']);
    }

    /**
     * Settles every zero-value unpaid invoice in one action.
     *
     * Not driven by the row checkboxes on purpose: the list is paginated, so a
     * selection can only ever cover the current page. These invoices tend to
     * arrive in the hundreds from an import, and the point is to clear all of
     * them at once.
     */
    public function markZeroValuePaid(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $marked = $this->invoices->markZeroValueUnpaidAsPaid();

        if ($marked > 0) {
            $this->activity->log(
                'admin',
                (int) $this->guard->currentAdmin()['id'],
                'invoice.zero_value_settled',
                'invoice',
                null,
                "Marked {$marked} zero-value invoice(s) as paid",
                $request->ip()
            );
        }

        return Response::redirect('/admin/invoices?zero_paid=' . $marked);
    }

    /**
     * Cancels every selected invoice that is still unpaid.
     *
     * Paid and refunded invoices are skipped rather than rejected, so one
     * already-settled invoice in a large selection doesn't block the rest —
     * the redirect reports how many were actually cancelled.
     */
    public function bulkCancel(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ids = array_map('intval', (array) $request->input('invoice_ids', []));
        $cancelled = $this->invoices->cancelManyUnpaid($ids);

        if ($cancelled > 0) {
            $this->activity->log(
                'admin',
                (int) $this->guard->currentAdmin()['id'],
                'invoice.bulk_cancelled',
                'invoice',
                null,
                "Bulk cancelled {$cancelled} invoice(s)",
                $request->ip()
            );
        }

        $skipped = max(0, count(array_unique(array_filter($ids))) - $cancelled);

        return Response::redirect('/admin/invoices?cancelled=' . $cancelled . '&skipped=' . $skipped);
    }

    private function createError(Request $request, string $message): Response
    {
        return $this->render('billing.invoice-create', [
            'clients' => $this->clients->all(),
            'error' => $message,
            'old' => [
                'client_id' => (string) $request->input('client_id', ''),
                'due_in_days' => (int) $request->input('due_in_days', 7),
                'items' => array_map(
                    static fn ($d, $a): array => ['description' => (string) $d, 'amount' => (string) $a],
                    (array) $request->input('item_description', []),
                    (array) $request->input('item_amount', [])
                ),
            ],
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $invoice = $this->invoices->find((int) $params['id']);

        if ($invoice === null) {
            return Response::html('404 Not Found', 404);
        }

        $currencyId = $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null;

        // An invoice that locked a currency displays in it. One that never
        // locked — imported, or raised by a path that skipped the currency
        // columns — falls back to the client's own currency, not the system
        // default, which is what printed "$7,501.50" against a naira invoice.
        // currency_rate is 1.0 on those rows, so nothing is re-converted.
        // Same rule as the invoice list and the client's own invoice pages.
        $invoiceClient = $this->clients->find((int) $invoice['client_id']);

        return $this->render('billing.invoice-show', [
            'invoice' => $invoice,
            'client' => $invoiceClient,
            'items' => $this->invoices->items((int) $invoice['id']),
            'transactions' => $this->transactions->forInvoice((int) $invoice['id']),
            'refundSuccess' => $request->query('refund_success'),
            'refundError' => $request->query('refund_error'),
            'reminderSent' => $request->query('reminder') === '1',
            'editSaved' => $request->query('edited') === '1',
            'editError' => $request->query('edit_error'),
            'reminderError' => $request->query('reminder_error'),
            'currency' => $currencyId !== null || $invoiceClient === null
                ? $this->currency->resolveLocked($currencyId)
                : $this->currency->resolveForClient($invoiceClient),
            'companyName' => (string) ($this->settings->get('company.name') ?? 'Your Company'),
            'companyEmail' => (string) ($this->settings->get('company.email') ?? ''),
            'companyDept' => (string) ($this->settings->get('company.billing_dept') ?? 'Payments Dept.'),
        ]);
    }

    public function downloadPdf(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $invoice = $this->invoices->find((int) $params['id']);

        if ($invoice === null) {
            return Response::html('404 Not Found', 404);
        }

        $client = $this->clients->find((int) $invoice['client_id']);
        $bytes = $this->pdf->build($invoice, $this->invoices->items((int) $invoice['id']), $client ?? []);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', "inline; filename=\"invoice-INV-{$invoice['id']}.pdf\"")
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    public function markPaid(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $invoice = $this->invoices->find($id);

        if ($invoice !== null && $invoice['status'] === 'unpaid') {
            // Only record the *remaining* balance — credit or a prior
            // partial payment may already cover part of the total, and
            // re-billing the full amount would double-count it.
            $remaining = round((float) $invoice['total'] - $this->transactions->totalCompletedForInvoice($id), 2);

            if ($remaining > 0) {
                $this->payments->recordPayment($id, 'manual', $remaining);
            }

            $formattedRemaining = $this->currency->formatLocked($remaining, $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null, (float) ($invoice['currency_rate'] ?? 1.0000));
            $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'invoice.paid', 'invoice', $id, "Marked invoice #{$id} as paid (manual, {$formattedRemaining} remaining balance)", $request->ip());
        }

        return Response::redirect("/admin/invoices/{$id}");
    }

    public function cancel(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $this->invoices->markCancelled($id);
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'invoice.cancelled', 'invoice', $id, "Cancelled invoice #{$id}", $request->ip());

        return Response::redirect("/admin/invoices/{$id}");
    }

    public function refund(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $invoiceId = (int) $params['id'];
        $invoice = $this->invoices->find($invoiceId);

        if ($invoice === null) {
            return Response::html('404 Not Found', 404);
        }

        $transactionId = (int) $request->input('transaction_id', 0);
        $transaction = $this->transactions->find($transactionId);

        if ($transaction === null || (int) $transaction['invoice_id'] !== $invoiceId) {
            return Response::redirect("/admin/invoices/{$invoiceId}?refund_error=" . urlencode('That transaction does not belong to this invoice.'));
        }

        $method = (string) $request->input('method', 'wallet');
        $rawAmount = trim((string) $request->input('amount', ''));
        $amount = $rawAmount !== '' ? (float) $rawAmount : null;

        $result = $method === 'gateway'
            ? $this->refunds->refundViaGateway($transactionId, $amount)
            : $this->refunds->refundToWallet($transactionId, $amount, trim((string) $request->input('reason', '')), (int) $this->guard->currentAdmin()['id']);

        $adminId = (int) $this->guard->currentAdmin()['id'];
        $this->activity->log(
            'admin',
            $adminId,
            'invoice.refunded',
            'invoice',
            $invoiceId,
            $result['success']
                ? "Refunded invoice #{$invoiceId} via {$method}: {$result['message']}"
                : "Refund attempt failed for invoice #{$invoiceId} via {$method}: {$result['message']}",
            $request->ip()
        );

        $query = $result['success']
            ? 'refund_success=' . urlencode($result['message'])
            : 'refund_error=' . urlencode($result['message']);

        return Response::redirect("/admin/invoices/{$invoiceId}?{$query}");
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

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Invoices',
            'content' => $content,
        ]));
    }
}
