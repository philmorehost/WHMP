<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Pdf\InvoicePdfBuilder;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Settings\SettingsRepository;
use CodeVault\View;

final class ClientInvoiceController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly InvoiceRepository $invoices,
        private readonly TransactionRepository $transactions,
        private readonly PaymentGatewayRepository $gateways,
        private readonly ClientCreditRepository $credit,
        private readonly CreditService $creditService,
        private readonly CurrencyService $currency,
        private readonly InvoicePdfBuilder $pdf,
        private readonly SettingsRepository $settings
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(6, min(60, (int) $request->query('per_page', 12)));
        $status = trim((string) $request->query('status', ''));
        $statusFilter = in_array($status, ['unpaid', 'paid', 'cancelled', 'refunded'], true) ? $status : null;

        $pagination = $this->paginateForClient((int) $client['id'], $statusFilter, $page, $perPage);

        foreach ($pagination['data'] as &$invoice) {
            $invoice['currency'] = $this->currency->resolveLocked($invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null);
        }
        unset($invoice);

        return $this->page('billing.client-invoices-index', [
            'pagination' => $pagination,
            'invoices' => $pagination['data'],
            'statusFilter' => $statusFilter ?? '',
            'currencyService' => $this->currency,
            // Used for invoices that never locked a currency, so they render
            // in this client's own currency rather than the system default.
            'clientCurrency' => $this->currency->resolveForClient($client),
        ]);
    }

    private function paginateForClient(int $clientId, ?string $status, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = 'WHERE client_id = ?';
        $bindings = [$clientId];

        if ($status !== null) {
            $where .= ' AND status = ?';
            $bindings[] = $status;
        }

        $db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);
        $totalRow = $db->selectOne("SELECT COUNT(*) AS c FROM invoices {$where}", $bindings);
        $total = (int) ($totalRow['c'] ?? 0);

        $data = $db->select(
            "SELECT * FROM invoices {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    public function show(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $invoice = $this->invoices->find((int) $params['id']);

        if ($invoice === null || (int) $invoice['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $currencyId = $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null;

        return $this->page('billing.client-invoice-show', [
            'invoice' => $invoice,
            'client' => $client,
            'items' => $this->invoices->items((int) $invoice['id']),
            'transactions' => $this->transactions->forInvoice((int) $invoice['id']),
            'gateways' => $this->gateways->allEnabled(),
            'creditBalance' => $this->credit->balance((int) $client['id']),
            // An invoice that locked a currency displays in it. One that never
            // locked (imported, or created before locking existed) is shown in
            // the client's own currency — resolveLocked() would fall back to
            // the system default and print "$" against a naira amount.
            // currency_rate is 1.0 on those rows, so nothing is converted.
            'currency' => $currencyId !== null
                ? $this->currency->resolveLocked($currencyId)
                : $this->currency->resolveForClient($client),
            // The client's own currency (not the invoice's locked currency) is needed
            // to display the wallet balance correctly — the two differ when the invoice
            // was created in a non-default currency but the client's preference is NGN.
            'clientCurrency' => $this->currency->resolveForClient($client),
            'paymentStatus' => $request->query('payment'),
            'companyName' => (string) ($this->settings->get('company.name') ?? 'Your Company'),
            'companyEmail' => (string) ($this->settings->get('company.email') ?? 'billing@example.com'),
            'companyDept' => (string) ($this->settings->get('company.billing_dept') ?? 'Payments Dept.'),
        ]);
    }

    public function downloadPdf(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $invoice = $this->invoices->find((int) $params['id']);

        if ($invoice === null || (int) $invoice['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $bytes = $this->pdf->build($invoice, $this->invoices->items((int) $invoice['id']), $client);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', "inline; filename=\"invoice-INV-{$invoice['id']}.pdf\"")
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    public function applyCredit(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $amountInput = $request->input('amount');
        $amount = $amountInput !== null && $amountInput !== '' ? (float) $amountInput : null;

        $this->creditService->applyToInvoice((int) $client['id'], (int) $params['id'], $amount);

        return Response::redirect("/client/invoices/{$params['id']}");
    }

    public function cancel(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $invoice = $this->invoices->find((int) $params['id']);

        if ($invoice !== null && (int) $invoice['client_id'] === (int) $client['id'] && $invoice['status'] === 'unpaid') {
            $this->invoices->markCancelled((int) $invoice['id']);
        }

        return Response::redirect("/client/invoices/" . (int) $params['id']);
    }

    public function addFundsForm(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $balance = $this->credit->balance((int) $client['id']);
        $currency = $this->currency->resolveForClient($client);
        $limits = $this->depositLimits($currency);

        return $this->page('billing.client-add-funds', [
            'creditBalance' => $balance,
            'currency' => $currency,
            // Resolved here rather than read off $currency in the template, so
            // the view cannot pick up a base currency whose row still carries a
            // rate above 1 and render the wallet balance multiplied by it.
            'currencyRate' => $this->currency->rateFor($currency),
            'minDeposit' => $limits['min'],
            'maxDeposit' => $limits['max'],
            'error' => $request->query('error'),
        ]);
    }

    /**
     * The configured deposit bounds expressed in the currency the client is
     * actually typing into the form.
     *
     * The settings are stored in the base currency, like every other stored
     * amount (see CurrencyService), but the figure a client enters is in their
     * own currency — so the bounds have to be converted before they can be
     * compared against it or shown next to the input. A maximum of 0 means
     * "no upper limit".
     *
     * @param array<string, mixed> $currency
     * @return array{min: float, max: float}
     */
    private function depositLimits(array $currency): array
    {
        // rateFor(), not the raw column: the base currency converts at 1.0
        // however its own row happens to read.
        $rate = $this->currency->rateFor($currency);

        $min = (float) $this->settings->get('billing.min_deposit', '10.00');
        $max = (float) $this->settings->get('billing.max_deposit', '10000.00');

        return [
            'min' => round($min * $rate, 2),
            'max' => $max > 0 ? round($max * $rate, 2) : 0.0,
        ];
    }

    public function addFundsSubmit(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $amount = (float) $request->input('amount', 0);
        $currency = $this->currency->resolveForClient($client);
        $limits = $this->depositLimits($currency);
        $symbol = (string) ($currency['symbol'] ?? '');

        // Compared in the client's own currency, because that is the unit the
        // figure was typed in — the configured bounds have already been
        // converted from base for exactly this reason.
        if ($amount < $limits['min'] || ($limits['max'] > 0 && $amount > $limits['max'])) {
            $message = $limits['max'] > 0
                ? sprintf('Amount must be between %s%s and %s%s.', $symbol, number_format($limits['min'], 2), $symbol, number_format($limits['max'], 2))
                : sprintf('Amount must be at least %s%s.', $symbol, number_format($limits['min'], 2));

            return Response::redirect('/client/wallet/add-funds?error=' . urlencode($message));
        }

        $db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $today = substr($now, 0, 10);

        // $amount is what the client typed while looking at bounds already
        // converted into their own currency (see depositLimits()) — it is a
        // figure denominated in that currency, not a base-currency amount
        // that needs converting. denominateFor() records which currency it's
        // in and locks rate at 1.0, the same shape AdminInvoiceController's
        // manual-invoice screen uses for exactly the same reason (see its own
        // comment): lockColumns() here would divide the typed figure down by
        // the live rate, and a second view that re-multiplies by that same
        // locked rate to display it would round-trip lossily back toward the
        // original number instead of showing it exactly, while any view that
        // (correctly, per the base-currency convention) does NOT re-multiply
        // would show the divided-down figure — the ₦10.00 / ₦14,900.00
        // mismatch this replaces.
        $currencyLock = $this->currency->denominateFor($client);
        $depositAmount = round($amount, 2);

        $invoiceId = (int) $db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, discount_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $client['id'],
                'unpaid',
                $depositAmount,
                0.00,
                0.00,
                $depositAmount,
                $currencyLock['currency_id'],
                $currencyLock['currency_rate'],
                $today,
                $now,
                $now
            ]
        );

        $db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, "Deposit / Add Funds to Wallet", $depositAmount]
        );

        return Response::redirect("/client/invoices/{$invoiceId}");
    }

    public function massPay(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $invoiceIds = $request->input('invoice_ids');
        if (!is_array($invoiceIds) || $invoiceIds === []) {
            return Response::redirect('/client/invoices');
        }

        $db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);
        $clientInvoices = $this->invoices->forClient((int) $client['id']);

        $unpaidSelected = [];
        $totalSubtotal = 0.0;
        $totalTax = 0.0;
        $totalDiscount = 0.0;
        $grandTotal = 0.0;

        foreach ($clientInvoices as $inv) {
            if ($inv['status'] === 'unpaid' && in_array((string) $inv['id'], array_map('strval', $invoiceIds), true)) {
                $unpaidSelected[] = $inv;
                $totalSubtotal += (float) $inv['subtotal'];
                $totalTax += (float) $inv['tax_amount'];
                $totalDiscount += (float) ($inv['discount_amount'] ?? 0.0);
                $grandTotal += (float) $inv['total'];
            }
        }

        if ($unpaidSelected === []) {
            return Response::redirect('/client/invoices');
        }

        // If only 1 invoice was selected, redirect straight to it
        if (count($unpaidSelected) === 1) {
            return Response::redirect("/client/invoices/" . (int) $unpaidSelected[0]['id']);
        }

        // Create consolidated Mass Payment invoice
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        // The summed totals are already base-currency amounts copied from the
        // source invoices, so the consolidated invoice only needs the matching
        // display lock — pairing a currency_id with a hardcoded 1.0 rate would
        // under-display it by the whole exchange rate.
        $currencyLock = $this->currency->lockColumns($this->currency->resolveForClient($client));

        $massInvoiceId = (int) $db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, discount_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $client['id'],
                'unpaid',
                $totalSubtotal,
                $totalTax,
                $totalDiscount,
                $grandTotal,
                $currencyLock['currency_id'],
                $currencyLock['currency_rate'],
                $today,
                $now,
                $now
            ]
        );

        foreach ($unpaidSelected as $inv) {
            $db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$massInvoiceId, "Mass Payment — Invoice #INV-{$inv['id']}", (float) $inv['total']]
            );
        }

        return Response::redirect("/client/invoices/{$massInvoiceId}");
    }

    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'My Invoices',
            'content' => $content,
        ]));
    }
}
