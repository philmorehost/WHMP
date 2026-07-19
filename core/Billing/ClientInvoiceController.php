<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Pdf\InvoicePdfBuilder;
use CodeVault\Request;
use CodeVault\Response;
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
        private readonly InvoicePdfBuilder $pdf
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
            'items' => $this->invoices->items((int) $invoice['id']),
            'transactions' => $this->transactions->forInvoice((int) $invoice['id']),
            'gateways' => $this->gateways->allEnabled(),
            'creditBalance' => $this->credit->balance((int) $client['id']),
            'currency' => $this->currency->resolveLocked($currencyId),
            'paymentStatus' => $request->query('payment'),
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

        $this->creditService->applyToInvoice((int) $client['id'], (int) $params['id']);

        return Response::redirect("/client/invoices/{$params['id']}");
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
