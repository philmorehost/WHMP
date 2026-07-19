<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Pdf\QuotePdfBuilder;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/** Client-side Quotes — view own, accept/decline, download PDF (R23). */
final class ClientQuoteController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly QuoteRepository $quotes,
        private readonly QuoteService $quoteService,
        private readonly QuotePdfBuilder $pdf
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->render('billing.client-quotes-index', [
            'quotes' => $this->quotes->forClient((int) $client['id']),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $quote = $this->quotes->find((int) $params['id']);

        if ($quote === null || (int) $quote['client_id'] !== (int) $client['id'] || $quote['status'] === 'draft') {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('billing.client-quote-show', [
            'quote' => $quote,
            'items' => $this->quotes->items((int) $quote['id']),
            'error' => null,
        ]);
    }

    public function accept(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $id = (int) $params['id'];
        $result = $this->quoteService->accept($id, (int) $client['id']);

        if (!$result['success']) {
            return $this->render('billing.client-quote-show', [
                'quote' => $this->quotes->find($id),
                'items' => $this->quotes->items($id),
                'error' => $result['error'],
            ]);
        }

        return Response::redirect("/client/invoices/{$result['invoiceId']}");
    }

    public function decline(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $id = (int) $params['id'];
        $this->quoteService->decline($id, (int) $client['id']);

        return Response::redirect("/client/quotes/{$id}");
    }

    public function downloadPdf(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $quote = $this->quotes->find((int) $params['id']);

        if ($quote === null || (int) $quote['client_id'] !== (int) $client['id'] || $quote['status'] === 'draft') {
            return Response::html('404 Not Found', 404);
        }

        $bytes = $this->pdf->build($quote, $this->quotes->items((int) $quote['id']), $client);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', "inline; filename=\"quote-Q-{$quote['id']}.pdf\"")
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'CodeVault — My Quotes',
            'content' => $content,
        ]));
    }
}
