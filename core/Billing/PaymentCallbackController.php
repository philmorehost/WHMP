<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Modules\GatewayModule;
use CodeVault\Modules\ModuleManager;
use CodeVault\Request;
use CodeVault\Response;

/**
 * Initiates a redirect-gateway payment and handles both ways a gateway
 * confirms it: the customer's browser landing back on /pay/{gateway}/callback,
 * and the gateway's own async webhook at /pay/{gateway}/webhook. Both paths
 * converge on the same rule — a charge is only ever recorded as paid after
 * a *server-side* verifyTransaction() call, never from trusting the
 * redirect's query string or an unverified webhook body alone.
 */
final class PaymentCallbackController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentGatewayRepository $gateways,
        private readonly TransactionRepository $transactions,
        private readonly PaymentService $payments,
        private readonly ModuleManager $modules,
        private readonly Config $config,
        private readonly Database $db
    ) {
    }

    public function initiate(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $invoiceId = (int) $params['id'];
        $invoice = $this->invoices->find($invoiceId);

        if ($invoice === null || (int) $invoice['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $slug = (string) $params['gateway'];
        $gatewayRow = $this->gateways->findBySlug($slug);
        $module = $this->modules->get(GatewayModule::class, $slug);

        if ($gatewayRow === null || (int) $gatewayRow['is_enabled'] !== 1 || !$module instanceof GatewayModule) {
            return Response::redirect("/client/invoices/{$invoiceId}");
        }

        $remaining = round((float) $invoice['total'] - $this->transactions->totalCompletedForInvoice($invoiceId), 2);

        if ($remaining <= 0) {
            return Response::redirect("/client/invoices/{$invoiceId}");
        }

        $reference = "cv-{$slug}-{$invoiceId}-" . bin2hex(random_bytes(6));
        $config = json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: [];
        $gatewayCurrency = strtoupper(trim((string) ($config['gateway_currency'] ?? 'NGN'))) ?: 'NGN';

        $invoiceCurrId = $invoice['currency_id'];
        $invoiceCurr = null;
        if ($invoiceCurrId !== null) {
            $invoiceCurr = $this->db->selectOne('SELECT code, exchange_rate FROM currencies WHERE id = ?', [$invoiceCurrId]);
        }
        $invoiceCode = $invoiceCurr !== null ? $invoiceCurr['code'] : 'USD';
        $invoiceRate = $invoiceCurr !== null ? (float) $invoiceCurr['exchange_rate'] : 1.0000;

        $gatewayCurr = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
        $gatewayRate = $gatewayCurr !== null ? (float) $gatewayCurr['exchange_rate'] : 1.0000;

        $captureAmount = $remaining;
        if ($invoiceCode !== $gatewayCurrency) {
            $captureAmount = round($remaining * ($gatewayRate / $invoiceRate), 2);
        }

        $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');

        $result = $module->capture([
            'config' => $config,
            'email' => (string) $client['email'],
            'amount' => $captureAmount,
            'currency' => $gatewayCurrency,
            'reference' => $reference,
            'callbackUrl' => "{$baseUrl}/pay/{$slug}/callback",
            'metadata' => ['invoice_id' => $invoiceId, 'client_id' => (int) $client['id']],
        ]);

        if (!$result['success'] || empty($result['redirectUrl'])) {
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error");
        }

        return Response::redirect($result['redirectUrl']);
    }

    public function callback(Request $request, array $params): Response
    {
        $slug = (string) $params['gateway'];
        $module = $this->modules->get(GatewayModule::class, $slug);

        if (!$module instanceof GatewayModule) {
            return Response::html('404 Not Found', 404);
        }

        // Paystack returns ?reference=...; Flutterwave returns
        // ?transaction_id=...&tx_ref=... (its own numeric id is what
        // verify takes, not the tx_ref we generated).
        $reference = (string) ($request->query('reference') ?? $request->query('transaction_id') ?? '');

        if ($reference === '') {
            return Response::redirect('/client/invoices');
        }

        $config = $this->configFor($slug);
        $verification = $module->verifyTransaction($reference, $config);
        $invoiceId = $this->invoiceIdFrom($verification);

        if ($invoiceId === null) {
            return Response::redirect('/client/invoices');
        }

        if ($verification['success']) {
            $this->recordIfNew($slug, $verification, $invoiceId);

            return Response::redirect("/client/invoices/{$invoiceId}?payment=success");
        }

        return Response::redirect("/client/invoices/{$invoiceId}?payment=failed");
    }

    public function webhook(Request $request, array $params): Response
    {
        $slug = (string) $params['gateway'];
        $module = $this->modules->get(GatewayModule::class, $slug);

        if (!$module instanceof GatewayModule) {
            return Response::html('unknown gateway', 404);
        }

        $config = $this->configFor($slug);

        if (!$this->verifiesSignature($slug, $request, $config)) {
            return Response::html('invalid signature', 401);
        }

        $callback = $module->handleCallback($request->input(), $request->headers());
        $data = $callback['data'];
        $reference = (string) ($data['reference'] ?? $data['tx_ref'] ?? '');

        if ($reference === '') {
            return Response::html('ok', 200);
        }

        $verification = $module->verifyTransaction($reference, $config);
        $invoiceId = $this->invoiceIdFrom($verification);

        if ($verification['success'] && $invoiceId !== null) {
            $this->recordIfNew($slug, $verification, $invoiceId);
        }

        return Response::html('ok', 200);
    }

    /** @param array{success: bool, status: string, reference: string, amount: float, metadata: array<string, mixed>} $verification */
    private function invoiceIdFrom(array $verification): ?int
    {
        $fromMetadata = $verification['metadata']['invoice_id'] ?? null;

        if ($fromMetadata !== null) {
            return (int) $fromMetadata;
        }

        // Fallback for a provider that doesn't echo metadata back on
        // verify: our own reference format is "cv-{slug}-{invoiceId}-{rand}".
        if (preg_match('/^cv-[a-z]+-(\d+)-/', $verification['reference'], $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /** @param array{success: bool, status: string, reference: string, amount: float, metadata: array<string, mixed>} $verification */
    private function recordIfNew(string $slug, array $verification, int $invoiceId): void
    {
        if ($this->transactions->findByGatewayTransactionId($slug, $verification['reference']) !== null) {
            return;
        }

        $this->payments->recordPayment($invoiceId, $slug, $verification['amount'], $verification['reference']);
    }

    /** @return array<string, mixed> */
    private function configFor(string $slug): array
    {
        $gatewayRow = $this->gateways->findBySlug($slug);

        return $gatewayRow !== null ? (json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: []) : [];
    }

    /** @param array<string, mixed> $config */
    private function verifiesSignature(string $slug, Request $request, array $config): bool
    {
        return match ($slug) {
            'paystack' => PaystackGateway::verifySignature(
                $request->rawBody(),
                (string) $request->header('X-Paystack-Signature', ''),
                (string) ($config['secret_key'] ?? '')
            ),
            'flutterwave' => FlutterwaveGateway::verifySignature(
                (string) $request->header('verif-hash', ''),
                (string) ($config['secret_hash'] ?? '')
            ),
            'payhub' => PayhubGateway::verifySignature(
                $request->rawBody(),
                (string) $request->header('X-Payhub-Signature', $request->header('x-payhub-signature', '')),
                (string) ($config['secret_key'] ?? '')
            ),
            'plisio' => PlisioGateway::verifySignature(
                $request->input(),
                (string) ($config['api_key'] ?? '')
            ),
            default => false,
        };
    }
}
