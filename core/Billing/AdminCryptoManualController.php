<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Database;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * The admin review queue for manual crypto payments: look at the uploaded
 * proof (and TXID), then approve — which records the payment through the
 * ordinary PaymentService pipeline (invoice marked paid, INVOICE_PAID fires,
 * a deposit invoice becomes wallet credit, domain renewals renew) — or
 * reject with a reason the client sees.
 *
 * Approval records the invoice's REMAINING balance, not the submission's
 * USD figure: the USD figure is what the client was told to send; the
 * books are settled in the invoice's own currency, by what is still owed.
 */
final class AdminCryptoManualController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly CryptoManualPaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly TransactionRepository $transactions,
        private readonly PaymentService $paymentsService,
        private readonly CurrencyService $currency,
        private readonly EmailDispatcher $email,
        private readonly ActivityLogger $activity,
        private readonly Database $db
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $queue = $this->payments->forReview();

        foreach (['pending' => $queue['pending'], 'reviewed' => $queue['reviewed']] as &$rows) {
            foreach ($rows as &$row) {
                $row['usd_display'] = $this->formatUsd($row);
            }
            unset($row);
        }
        unset($rows);

        return $this->render('billing.crypto-payments-admin', [
            'pending' => $queue['pending'],
            'reviewed' => $queue['reviewed'],
            'notice' => $request->query('notice'),
            'error' => $request->query('error'),
        ]);
    }

    /**
     * The proof image/PDF — permission-gated (the file sits outside the
     * web root and is only ever served through here).
     */
    public function proof(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $payment = $this->payments->find((int) ($params['id'] ?? 0));

        if ($payment === null) {
            return Response::html('404 Not Found', 404);
        }

        $proofs = new CryptoManualProofService();
        $file = $proofs->fileFor($payment);

        if ($file === null) {
            return Response::html('404 Not Found', 404);
        }

        $bytes = (string) file_get_contents($file['path']);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Disposition', 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Approve = "the USDT arrived". Records the invoice's remaining balance
     * through PaymentService (deduped by the submission reference), stamps
     * the row approved, and emails the client. Deposit invoices become
     * wallet credit through the ordinary INVOICE_PAID pipeline — nothing
     * crypto-specific is wired into payment handling.
     */
    public function approve(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $payment = $this->payments->find((int) ($params['id'] ?? 0));

        if ($payment === null) {
            return $this->back('error', 'That payment could not be found.');
        }

        if ((string) $payment['status'] !== 'pending') {
            return $this->back('error', 'That payment was already reviewed.');
        }

        $invoice = $this->invoices->find((int) $payment['invoice_id']);
        $adminId = (int) $this->guard->currentAdmin()['id'];
        $note = trim((string) $request->input('admin_note', ''));

        if ($invoice === null) {
            $this->payments->markRejected($payment['id'], $adminId, 'The invoice no longer exists — nothing to apply this payment to.');
            return $this->back('error', 'The invoice for this payment no longer exists — the submission was rejected.');
        }

        $remaining = round((float) $invoice['total'] - $this->transactions->totalCompletedForInvoice((int) $invoice['id']), 2);

        if ($remaining <= 0) {
            // Someone (or another gateway) paid it while the proof sat in
            // the queue. Do not record a second payment — reject the
            // submission with that explanation instead.
            $this->payments->markRejected($payment['id'], $adminId, 'The invoice was already paid by another payment before this proof was reviewed.');
            return $this->back('error', 'That invoice was already paid — the submission was closed without recording a second payment.');
        }

        $result = $this->paymentsService->recordPayment(
            (int) $invoice['id'],
            CryptoManualGateway::SLUG,
            $remaining,
            'cryptomanual-' . (int) $payment['id']
        );

        if ($result['transactionId'] === 0) {
            // Unique (gateway, transaction) reference already used — this
            // exact submission was approved a moment ago (double click).
            return $this->back('error', 'This payment was just approved — nothing further was recorded.');
        }

        $this->payments->markApproved((int) $payment['id'], $adminId, $note, (int) $result['transactionId']);

        $this->activity->log(
            'admin',
            $adminId,
            'manual_crypto.approved',
            'manual_crypto_payment',
            (int) $payment['id'],
            "Manual crypto payment #{$payment['id']} approved for invoice #{$invoice['id']} (\${$payment['amount_usd']} USDT) — transaction #{$result['transactionId']}."
        );

        $this->emailClient(
            (int) $payment['client_id'],
            "Your cryptocurrency payment for invoice #{$invoice['id']} was approved",
            '<p style="margin:0 0 10px">Good news — your USDT payment was verified and approved.</p>'
            . '<p style="margin:0">Invoice <strong>#' . (int) $invoice['id'] . '</strong> is now marked <strong>paid</strong>. Thank you!</p>'
        );

        return $this->back('notice', 'Payment approved — invoice #' . (int) $invoice['id'] . ' is marked paid.');
    }

    /**
     * Reject = the proof does not check out (wrong amount, transfer never
     * landed, screenshot of something else). The client is told why and can
     * submit a corrected proof — the invoice stays unpaid and a NEW
     * submission is expected, never an edit of the rejected one.
     */
    public function reject(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $payment = $this->payments->find((int) ($params['id'] ?? 0));

        if ($payment === null) {
            return $this->back('error', 'That payment could not be found.');
        }

        if ((string) $payment['status'] !== 'pending') {
            return $this->back('error', 'That payment was already reviewed.');
        }

        $reason = trim((string) $request->input('reason', ''));

        if ($reason === '') {
            return $this->back('error', 'A rejection reason is required — the client needs to know what to fix.');
        }

        $adminId = (int) $this->guard->currentAdmin()['id'];
        $this->payments->markRejected((int) $payment['id'], $adminId, $reason);

        $this->activity->log(
            'admin',
            $adminId,
            'manual_crypto.rejected',
            'manual_crypto_payment',
            (int) $payment['id'],
            "Manual crypto payment #{$payment['id']} rejected: {$reason}"
        );

        $this->emailClient(
            (int) $payment['client_id'],
            "Your cryptocurrency payment proof for invoice #{$payment['invoice_id']} needs attention",
            '<p style="margin:0 0 10px">We could not verify your cryptocurrency payment proof for invoice <strong>#' . (int) $payment['invoice_id'] . '</strong>.</p>'
            . '<p style="margin:0 0 10px"><strong>Reason:</strong> ' . htmlspecialchars($reason, ENT_QUOTES) . '</p>'
            . '<p style="margin:0">The invoice remains unpaid. You can submit a new proof from the invoice page once the issue is fixed.</p>'
        );

        return $this->back('notice', 'Payment rejected and the client notified.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatUsd(array $row): string
    {
        // amount_usd is stored converted to USD; the currency/rate columns
        // record HOW it was locked (NULL + 1.0 on a USD-base install). The
        // dollar figure is display-only — the books use the invoice total.
        return '$' . number_format((float) $row['amount_usd'], 2);
    }

    private function emailClient(int $clientId, string $subject, string $htmlBody): void
    {
        $client = $this->db->selectOne('SELECT email FROM clients WHERE id = ?', [$clientId]);

        if ($client === null || trim((string) $client['email']) === '') {
            return;
        }

        $html = '<div style="font-family:Arial,sans-serif; font-size:14px; color:#111827">' . $htmlBody . '</div>';

        try {
            $this->email->sendRaw($subject, $html, (string) $client['email'], $clientId);
        } catch (\Throwable) {
            // The decision is already recorded; an email outage must not
            // roll it back. The client also sees the status in their area.
        }
    }

    private function back(string $kind, string $message): Response
    {
        return Response::redirect('/admin/crypto-payments?' . $kind . '=' . urlencode($message));
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
            'title' => 'CodeVault Admin — Crypto Payments',
            'content' => $content,
        ]));
    }
}
