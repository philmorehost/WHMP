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
        private readonly InvoicePdfBuilder $pdf
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->query('status', '');
        $page = max(1, (int) $request->query('page', 1));

        return $this->render('billing.invoices-index', [
            'results' => $this->invoices->paginate($status !== '' ? $status : null, $page),
            'statusFilter' => $status,
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

        return $this->render('billing.invoice-show', [
            'invoice' => $invoice,
            'items' => $this->invoices->items((int) $invoice['id']),
            'transactions' => $this->transactions->forInvoice((int) $invoice['id']),
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
