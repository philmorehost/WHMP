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

        $invoices = $this->invoices->forClient((int) $client['id']);

        foreach ($invoices as &$invoice) {
            $invoice['currency'] = $this->currency->resolveLocked($invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null);
        }
        unset($invoice);

        return $this->page('billing.client-invoices-index', [
            'invoices' => $invoices,
        ]);
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
            'currency' => $this->currency->resolveLocked($currencyId),
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
        $rate = (float) ($currency['exchange_rate'] ?? 1.0);

        if ($rate <= 0) {
            $rate = 1.0;
        }

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
        $currencyLock = $this->currency->lockColumns($currency);

        // $amount is what the client typed, i.e. in the currency they are being
        // shown (e.g. NGN). Every stored monetary amount is authoritative in the
        // BASE currency (see CurrencyService) and is converted back up for
        // display via the locked rate — so divide by that same locked rate here.
        // Storing the typed figure directly would make a ₦100 top-up render as
        // ₦100 × rate on every screen that formats it.
        $lockedRate = (float) $currencyLock['currency_rate'] > 0 ? (float) $currencyLock['currency_rate'] : 1.0;
        $baseAmount = round($amount / $lockedRate, 2);

        $invoiceId = (int) $db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, discount_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $client['id'],
                'unpaid',
                $baseAmount,
                0.00,
                0.00,
                $baseAmount,
                $currencyLock['currency_id'],
                $currencyLock['currency_rate'],
                $today,
                $now,
                $now
            ]
        );

        $db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, "Deposit / Add Funds to Wallet", $baseAmount]
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
