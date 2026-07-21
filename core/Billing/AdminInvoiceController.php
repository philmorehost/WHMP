<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Pdf\InvoicePdfBuilder;
use CodeVault\Request;
use CodeVault\Response;
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
        private readonly CurrencyService $currency
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

        // Same currency-locking rule as InvoiceRepository::paginate(): a
        // NULL currency_id is the base currency, full stop — never the
        // client's current preference — and the per-row rate has to be
        // applied inside each SUM(), since i.total is stored unconverted.
        $currencyStats = $db->select(
            "SELECT
                curr.code AS currency_code,
                curr.symbol AS currency_symbol,
                SUM(CASE WHEN i.status = 'paid' THEN i.total * COALESCE(i.currency_rate, 1) ELSE 0 END) AS total_paid,
                SUM(CASE WHEN i.status = 'unpaid' THEN i.total * COALESCE(i.currency_rate, 1) ELSE 0 END) AS total_unpaid,
                SUM(CASE WHEN i.status = 'unpaid' AND i.due_date < ? THEN i.total * COALESCE(i.currency_rate, 1) ELSE 0 END) AS total_overdue
             FROM invoices i
             JOIN clients c ON c.id = i.client_id
             LEFT JOIN currencies curr ON curr.id = COALESCE(i.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
             GROUP BY curr.id",
             [(new \DateTimeImmutable())->format('Y-m-d')]
        );

        return $this->render('billing.invoices-index', [
            'results' => $this->invoices->paginate($status !== '' ? $status : null, $page),
            'statusFilter' => $status,
            'currencyStats' => $currencyStats,
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

        return $this->render('billing.invoice-show', [
            'invoice' => $invoice,
            'items' => $this->invoices->items((int) $invoice['id']),
            'transactions' => $this->transactions->forInvoice((int) $invoice['id']),
            'refundSuccess' => $request->query('refund_success'),
            'refundError' => $request->query('refund_error'),
            'currency' => $this->currency->resolveLocked($currencyId),
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

            $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'invoice.paid', 'invoice', $id, "Marked invoice #{$id} as paid (manual, \${$remaining} remaining balance)", $request->ip());
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
