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
        private readonly Database $db,
        private readonly PaymentMethodRepository $paymentMethods
    ) {
    }

    public function initiate(Request $request, array $params): Response
    {
        $slug = (string) ($params['gateway'] ?? 'unknown');
        $invoiceId = (int) ($params['id'] ?? 0);
        error_log("[PAYMENT] ==> initiate() started: gateway={$slug} invoice_id={$invoiceId}");

        $client = $this->guard->currentClient();

        if ($client === null) {
            error_log("[PAYMENT] ERROR: Client not authenticated");
            return Response::redirect('/client/login');
        }

        error_log("[PAYMENT] Client authenticated: client_id=" . (int) $client['id']);

        $invoice = $this->invoices->find($invoiceId);

        if ($invoice === null || (int) $invoice['client_id'] !== (int) $client['id']) {
            error_log("[PAYMENT] ERROR: Invoice not found or permission denied: invoice_id={$invoiceId}");
            return Response::html('404 Not Found', 404);
        }

        error_log("[PAYMENT] Invoice found: total=" . (float) $invoice['total']);

        $gatewayRow = $this->gateways->findBySlug($slug);
        $module = $this->modules->get(GatewayModule::class, $slug);

        if ($gatewayRow === null || (int) $gatewayRow['is_enabled'] !== 1 || !$module instanceof GatewayModule) {
            error_log("[PAYMENT] ERROR: Gateway not configured: slug={$slug} found=" . ($gatewayRow !== null ? 'yes' : 'no') . " enabled=" . (($gatewayRow !== null && (int) $gatewayRow['is_enabled'] === 1) ? 'yes' : 'no'));
            $msg = urlencode("The {$slug} payment gateway is currently disabled or not installed.");
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg={$msg}");
        }

        error_log("[PAYMENT] Gateway module loaded successfully");

        $remaining = round((float) $invoice['total'] - $this->transactions->totalCompletedForInvoice($invoiceId), 2);

        if ($remaining <= 0) {
            error_log("[PAYMENT] Invoice already paid, redirecting to success");
            return Response::redirect("/client/invoices/{$invoiceId}?payment=success");
        }

        error_log("[PAYMENT] Remaining balance to pay: {$remaining}");

        $reference = "cv-{$slug}-{$invoiceId}-" . bin2hex(random_bytes(6));
        $config = json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: [];
        $gatewayCurrency = strtoupper(trim((string) ($config['gateway_currency'] ?? 'NGN'))) ?: 'NGN';

        $gatewayCurr = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
        $gatewayRate = $gatewayCurr !== null ? (float) $gatewayCurr['exchange_rate'] : 1.0000;

        // $remaining is always in base currency. Convert it to the gateway's expected currency.
        $captureAmount = round($remaining * $gatewayRate, 2);

        $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');

        error_log("[PAYMENT] Capture details: amount={$captureAmount} currency={$gatewayCurrency} rate={$gatewayRate} reference={$reference}");

        // Without this try/catch, any exception from the gateway module (a
        // missing PHP extension the module's HTTP client needs, a network
        // library error, etc.) propagates as an uncaught fatal error — the
        // customer's browser just gets a blank/500 page with no indication
        // payment even attempted to start, which is indistinguishable from
        // the button "not responding". Converting it to the same error
        // banner every other failure path already uses makes the real
        // reason visible instead.
        $clientName = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));

        try {
            error_log("[PAYMENT] Calling gateway->capture() for {$slug}...");
            $result = $module->capture([
                'config' => $config,
                'email' => (string) $client['email'],
                // PayHub's initialize endpoint requires name + phone (not just
                // email); other gateway modules simply ignore params they don't use.
                'name' => $clientName !== '' ? $clientName : (string) $client['email'],
                'phone' => (string) ($client['phone'] ?? ''),
                'amount' => $captureAmount,
                'currency' => $gatewayCurrency,
                'reference' => $reference,
                'callbackUrl' => "{$baseUrl}/pay/{$slug}/callback",
                'metadata' => ['invoice_id' => $invoiceId, 'client_id' => (int) $client['id']],
            ]);
            error_log("[PAYMENT] gateway->capture() returned: success=" . ($result['success'] ? 'true' : 'false') . " message=" . (string) ($result['message'] ?? ''));
        } catch (\Throwable $e) {
            error_log("[PAYMENT] CRITICAL ERROR: gateway->capture() threw exception: " . get_class($e) . ": " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $errMsg = urlencode('Payment gateway error: ' . $e->getMessage());
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg={$errMsg}");
        }

        if (!$result['success'] || empty($result['redirectUrl'])) {
            error_log("[PAYMENT] Gateway returned failure: success=" . ($result['success'] ? 'true' : 'false') . " has_url=" . (!empty($result['redirectUrl']) ? 'yes' : 'no'));
            $errMsg = urlencode($result['message'] ?? 'Payment initialization failed. Please verify gateway settings.');
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg={$errMsg}");
        }

        error_log("[PAYMENT] SUCCESS: Redirecting to gateway checkout at " . substr((string) $result['redirectUrl'], 0, 100) . "...");
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
        // verify takes, not the tx_ref we generated); PayPal returns
        // ?token=...&PayerID=... where token is the PayPal order id
        // (see PaypalGateway::capture()'s transactionId).
        $reference = (string) ($request->query('reference') ?? $request->query('trxref') ?? $request->query('transaction_id') ?? $request->query('token') ?? '');

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
            error_log("[PAYMENT] Payment verified successfully for invoice {$invoiceId}");

            // The gateway may have returned the amount in a different currency
            // than the invoice's base currency. Convert it back to the base
            // currency so the amount can be correctly matched against the
            // invoice total. Example: invoice is 100,000 NGN, gateway is USD,
            // client pays 250 USD, gateway verifies 250 USD. We need to convert
            // 250 USD back to ~100,000 NGN before recording.
            $gatewayCurrency = strtoupper(trim((string) ($config['gateway_currency'] ?? 'NGN'))) ?: 'NGN';
            $gatewayCurr = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
            $gatewayRate = $gatewayCurr !== null ? (float) $gatewayCurr['exchange_rate'] : 1.0000;

            $amountInGatewayGurrency = $verification['amount'];

            // Convert amount from gateway currency back to base currency (inverse rate).
            if ($gatewayRate > 0) {
                $verification['amount'] = $verification['amount'] / $gatewayRate;
                error_log("[PAYMENT] Currency conversion: {$amountInGatewayGurrency} {$gatewayCurrency} (rate={$gatewayRate}) → {$verification['amount']} base currency");
            }

            $this->recordIfNew($slug, $verification, $invoiceId);

            return Response::redirect("/client/invoices/{$invoiceId}?payment=success");
        }

        error_log("[PAYMENT] Payment verification FAILED for invoice {$invoiceId}");
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

        if (!$this->verifiesSignature($slug, $request, $config, $module)) {
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
            // Same currency conversion logic as callback() — convert amount from
            // gateway currency back to base currency before recording.
            $gatewayCurrency = strtoupper(trim((string) ($config['gateway_currency'] ?? 'NGN'))) ?: 'NGN';
            $gatewayCurr = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
            $gatewayRate = $gatewayCurr !== null ? (float) $gatewayCurr['exchange_rate'] : 1.0000;

            $amountInGatewayGurrency = $verification['amount'];

            if ($gatewayRate > 0) {
                $verification['amount'] = $verification['amount'] / $gatewayRate;
                error_log("[PAYMENT-WEBHOOK] Currency conversion: {$amountInGatewayGurrency} {$gatewayCurrency} → {$verification['amount']} base currency");
            }

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
        $this->capturePaymentMethod($slug, $verification);
    }

    /**
     * If the gateway handed back a reusable card authorization on a
     * successful charge, save it as the client's payment method so future
     * invoices can be auto-charged without another redirect. Best-effort:
     * anything missing (no authorization, no client id) just means no method
     * is saved — never blocks recording the payment itself.
     *
     * @param array<string, mixed> $verification
     */
    private function capturePaymentMethod(string $slug, array $verification): void
    {
        $authorization = $verification['authorization'] ?? null;
        $clientId = (int) ($verification['metadata']['client_id'] ?? 0);

        if (!is_array($authorization) || ($authorization['token'] ?? '') === '' || $clientId <= 0) {
            return;
        }

        $this->paymentMethods->store($clientId, $slug, (string) $authorization['token'], [
            'brand' => $authorization['brand'] ?? null,
            'last4' => $authorization['last4'] ?? null,
            'exp_month' => $authorization['exp_month'] ?? null,
            'exp_year' => $authorization['exp_year'] ?? null,
        ]);
    }

    /** @return array<string, mixed> */
    private function configFor(string $slug): array
    {
        $gatewayRow = $this->gateways->findBySlug($slug);

        return $gatewayRow !== null ? (json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: []) : [];
    }

    /** @param array<string, mixed> $config */
    private function verifiesSignature(string $slug, Request $request, array $config, GatewayModule $module): bool
    {
        // PayPal has no local shared-secret HMAC to check — verifying it
        // needs a live API call (PaypalGateway::verifySignature(), an
        // instance method since it needs $this->http + an OAuth token),
        // so it's handled by instanceof rather than the static-method
        // match every other gateway here uses.
        if ($module instanceof PaypalGateway) {
            return $module->verifySignature($request->rawBody(), $request->headers(), $config);
        }

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
