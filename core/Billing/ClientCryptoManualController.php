<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Database;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Notifications\NotificationDispatcher;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Settings\SettingsRepository;
use CodeVault\View;

/**
 * The client side of manual crypto payments (USDT TRC-20): show the wallet,
 * the USD amount to send, and take the proof upload. Nothing here marks an
 * invoice paid — approval is an admin action on the review queue; this side
 * only ever creates a `pending` submission row.
 */
final class ClientCryptoManualController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly InvoiceRepository $invoices,
        private readonly TransactionRepository $transactions,
        private readonly PaymentGatewayRepository $gateways,
        private readonly CryptoManualPaymentRepository $payments,
        private readonly CryptoManualProofService $proofs,
        private readonly CurrencyService $currency,
        private readonly SettingsRepository $settings,
        private readonly EmailDispatcher $email,
        private readonly NotificationDispatcher $notifications,
        private readonly Database $db
    ) {
    }

    /**
     * Instructions + proof-upload page. Reached from "Pay with Crypto —
     * USDT (TRC-20)" on the invoice (PaymentCallbackController branches
     * here instead of asking the gateway module to capture). Re-checks
     * everything the pay button's POST checked — this page is itself a
     * hand-craftable URL.
     */
    public function instructions(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $invoiceId = (int) ($params['id'] ?? 0);
        $invoice = $this->invoices->find($invoiceId);

        if ($invoice === null || (int) $invoice['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $blocked = $this->blockedReason($client);

        if ($blocked !== null) {
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg=" . urlencode($blocked));
        }

        $remaining = round((float) $invoice['total'] - $this->transactions->totalCompletedForInvoice($invoiceId), 2);

        if ($remaining <= 0) {
            return Response::redirect("/client/invoices/{$invoiceId}?payment=success");
        }

        // The USD figure, recomputed on every visit until the client locks
        // it in by submitting — the invoice may sit for days, and quoting a
        // stale rate would make the client send the wrong amount. Submit is
        // what freezes it (repository row).
        $usd = $this->usdFigure($invoice, $remaining);
        $config = $this->gatewayConfig();
        $wallet = CryptoManualGateway::walletAddress($config);

        return $this->page('billing.crypto-manual-pay', [
            'invoice' => $invoice,
            'currency' => $this->currency->resolveLocked($invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null),
            'remaining' => $remaining,
            'usdAmount' => $usd['amount'],
            'wallet' => $wallet,
            'walletValid' => $wallet !== '' && CryptoManualGateway::walletAddressIsValid($wallet),
            'instructions' => trim((string) ($config['instructions'] ?? '')),
            'pending' => $this->payments->pendingForInvoice($invoiceId),
            'history' => $this->payments->forInvoice($invoiceId),
            'companyName' => (string) ($this->settings->get('company.name') ?? 'Your Company'),
            'companyEmail' => (string) ($this->settings->get('company.email') ?? 'billing@example.com'),
            'submitted' => $request->query('submitted') === '1',
            'error' => $request->query('error'),
        ]);
    }

    /**
     * The proof upload. Creates exactly one `pending` row per attempt; an
     * invoice may only have one under review at a time (a second upload
     * while one is pending would let a client stack contradictory claims
     * about the same balance). The USD amount + rate + wallet are recorded
     * HERE — the moment of submission — and never recomputed afterwards.
     */
    public function submit(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $invoiceId = (int) ($params['id'] ?? 0);
        $invoice = $this->invoices->find($invoiceId);

        if ($invoice === null || (int) $invoice['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $back = "/client/invoices/{$invoiceId}/crypto";

        $blocked = $this->blockedReason($client);

        if ($blocked !== null) {
            return Response::redirect($back . '?error=' . urlencode($blocked));
        }

        if ($this->payments->pendingForInvoice($invoiceId) !== null) {
            return Response::redirect($back . '?error=' . urlencode('A payment is already awaiting review for this invoice.'));
        }

        $remaining = round((float) $invoice['total'] - $this->transactions->totalCompletedForInvoice($invoiceId), 2);

        if ($remaining <= 0) {
            return Response::redirect("/client/invoices/{$invoiceId}?payment=success");
        }

        $txid = strtoupper(trim((string) $request->input('txid', '')));
        $txid = preg_replace('/^0X/', '', $txid) ?? $txid;

        // TRON transaction hashes are 64 hex characters. Kept loose (12–80
        // hex) so partial copies from explorers still save — the proof
        // image is the evidence of record; the txid only helps the admin
        // look the transfer up.
        if ($txid !== '' && preg_match('/^[0-9A-F]{12,80}$/', $txid) !== 1) {
            return Response::redirect($back . '?error=' . urlencode('The transaction hash looks invalid — it should be the hexadecimal TXID from your wallet or explorer.'));
        }

        $proof = $this->proofs->store($request->file('proof'));

        if (!$proof['ok']) {
            return Response::redirect($back . '?error=' . urlencode((string) $proof['error']));
        }

        $config = $this->gatewayConfig();
        $usd = $this->usdFigure($invoice, $remaining);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $reference = 'cv-' . CryptoManualGateway::SLUG . "-{$invoiceId}-" . bin2hex(random_bytes(6));

        $paymentId = $this->payments->create([
            'invoice_id' => $invoiceId,
            'client_id' => (int) $client['id'],
            'reference' => $reference,
            'amount_usd' => $usd['amount'],
            'usd_currency_id' => $usd['currencyId'],
            'usd_rate' => $usd['rate'],
            'wallet_address' => CryptoManualGateway::walletAddress($config),
            'txid' => $txid !== '' ? $txid : null,
            'proof_path' => $proof['path'],
            'proof_name' => $proof['name'],
            'proof_mime' => $proof['mime'],
            'proof_size' => $proof['size'],
            'note' => trim((string) $request->input('note', '')),
            'created_at' => $now,
        ]);

        $this->notifyAdmins($client, $invoice, $usd['amount'], $paymentId);
        $this->notifications->dispatch(
            'ManualCryptoSubmitted',
            "Manual crypto payment #{$paymentId} awaiting review — " . trim((string) ($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')) . " sent \${$usd['amount']} for invoice #{$invoiceId}.",
            ['paymentId' => $paymentId, 'invoiceId' => $invoiceId]
        );

        return Response::redirect($back . '?submitted=1');
    }

    /**
     * Why the gateway must not be offered to this client right now, or null
     * when it may. The same audience rule the gateway list and the payment
     * initiation enforce — repeated here because this page is reachable by
     * URL without going through either.
     *
     * @param array<string, mixed> $client
     */
    private function blockedReason(array $client): ?string
    {
        $gatewayRow = $this->gateways->findBySlug(CryptoManualGateway::SLUG);
        $config = $gatewayRow !== null ? (json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: []) : [];

        if ($gatewayRow === null || (int) $gatewayRow['is_enabled'] !== 1) {
            return 'Manual cryptocurrency payments are currently unavailable.';
        }

        $isStorefront = PaymentGatewayRepository::isResellerClient($client);

        if ($isStorefront && (int) ($gatewayRow['reseller_enabled'] ?? 1) !== 1) {
            return 'Manual cryptocurrency payments are not available on this account.';
        }

        $isResellerOwner = !$isStorefront && $this->isResellerOwner((int) $client['id']);

        if (!CryptoManualGateway::audienceAllows($config, $isStorefront, $isResellerOwner)) {
            return 'Manual cryptocurrency payments are not available on this account.';
        }

        if (!CryptoManualGateway::walletAddressIsValid(CryptoManualGateway::walletAddress($config))) {
            return 'Cryptocurrency payments are being configured — please use another payment method or contact support.';
        }

        return null;
    }

    private function isResellerOwner(int $clientId): bool
    {
        return $this->db->selectOne('SELECT id FROM resellers WHERE client_id = ? LIMIT 1', [$clientId]) !== null;
    }

    /**
     * The USD quote: the invoice's remaining balance (what the client
     * actually still owes, in the invoice's own currency) crossed to USD.
     * USD is the quote currency for crypto exactly as it is for Plisio —
     * the invoice itself may be naira or euros; what the client sends on
     * TRON is dollars.
     *
     * @param array<string, mixed> $invoice
     * @return array{amount: float, rate: float, currencyId: int|null}
     */
    private function usdFigure(array $invoice, float $remaining): array
    {
        $invoiceCurrencyId = $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null;
        $invoiceRate = (float) ($invoice['currency_rate'] ?? 1.0);
        $shownAmount = round($remaining * ($invoiceCurrencyId !== null && $invoiceRate > 0 ? $invoiceRate : 1.0), 2);
        $usdAmount = round($this->currency->crossConvert($shownAmount, $this->currency->codeFor($invoiceCurrencyId), 'USD'), 2);

        // Same NULL-means-base convention every stored amount uses: on a USD
        // base install the row records NULL + rate 1.0; otherwise the USD
        // currency row's id and the rate it was locked at.
        $usdRate = $this->currency->rateForCode('USD');
        $usdRate = $usdRate > 0 ? $usdRate : 1.0;

        return [
            'amount' => $usdAmount > 0 ? $usdAmount : $shownAmount,
            'rate' => $usdRate,
            'currencyId' => $usdRate === 1.0 ? null : $this->currency->currencyIdForCode('USD'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayConfig(): array
    {
        $gatewayRow = $this->gateways->findBySlug(CryptoManualGateway::SLUG);

        return $gatewayRow !== null ? (json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: []) : [];
    }

    /**
     * Tell the billing inbox a proof is waiting — the admin is the actor
     * who has to move next; nothing proceeds until a human looks at it.
     *
     * @param array<string, mixed> $client
     * @param array<string, mixed> $invoice
     */
    private function notifyAdmins(array $client, array $invoice, float $usdAmount, int $paymentId): void
    {
        $to = trim((string) ($this->settings->get('company.email') ?? ''));

        if ($to === '') {
            return;
        }

        $name = trim((string) ($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
        $subject = "Manual crypto payment #{$paymentId} awaiting review — invoice #{$invoice['id']}";

        $html = '<p style="margin:0 0 12px">A client submitted manual cryptocurrency payment proof and it is awaiting review:</p>'
            . '<table cellpadding="6" style="border-collapse:collapse; font-size:14px">'
            . '<tr><td><strong>Client</strong></td><td>' . htmlspecialchars($name !== '' ? $name : (string) $client['email'], ENT_QUOTES) . ' &lt;' . htmlspecialchars((string) $client['email'], ENT_QUOTES) . '&gt;</td></tr>'
            . '<tr><td><strong>Invoice</strong></td><td>#' . (int) $invoice['id'] . '</td></tr>'
            . '<tr><td><strong>Amount to expect</strong></td><td>$' . number_format($usdAmount, 2) . ' (USDT TRC-20)</td></tr>'
            . '</table>'
            . '<p style="margin:12px 0 0">Review it under <strong>Admin → Billing → Crypto payments</strong>. Approving marks the invoice paid; rejecting returns it to the client with your reason.</p>';

        try {
            $this->email->sendRaw($subject, $html, $to, (int) $client['id']);
        } catch (\Throwable) {
            // The submission is already stored — an email outage must not
            // lose it. The in-app notification and the queue itself remain.
        }
    }

    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Pay with Cryptocurrency',
            'content' => $content,
        ]));
    }
}
