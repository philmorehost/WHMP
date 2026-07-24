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

        return $this->page('billing.client-add-funds', [
            'creditBalance' => $balance,
            'currency' => $currency,
            'error' => $request->query('error'),
        ]);
    }

    public function addFundsSubmit(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $amount = (float) $request->input('amount', 0);
        if ($amount < 10.00 || $amount > 10000.00) {
            return Response::redirect('/client/wallet/add-funds?error=' . urlencode('Amount must be between 10.00 and 10,000.00.'));
        }

        $db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $currency = $this->currency->resolveForClient($client);

        $invoiceId = (int) $db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, discount_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $client['id'],
                'unpaid',
                $amount,
                0.00,
                0.00,
                $amount,
                $currency['id'],
                1.0000,
                $today,
                $now,
                $now
            ]
        );

        $db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, "Deposit / Add Funds to Wallet", $amount]
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
        $currency = $this->currency->resolveForClient($client);

        $massInvoiceId = (int) $db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, discount_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $client['id'],
                'unpaid',
                $totalSubtotal,
                $totalTax,
                $totalDiscount,
                $grandTotal,
                $currency['id'],
                1.0000,
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
