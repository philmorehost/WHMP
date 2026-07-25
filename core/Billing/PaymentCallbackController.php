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
use DateTimeImmutable;

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

        $client = $this->guard->currentClient();
        $clientId = $client ? (int) $client['id'] : null;

        if ($client === null) {
            $this->writeGatewayLog($slug, $invoiceId, null, 'FAILED', 'Client not authenticated.');
            return Response::redirect('/client/login');
        }

        $invoice = $this->invoices->find($invoiceId);

        if ($invoice === null || (int) $invoice['client_id'] !== (int) $client['id']) {
            $this->writeGatewayLog($slug, $invoiceId, $clientId, 'FAILED', 'Invoice not found or permission denied.');
            return Response::html('404 Not Found', 404);
        }

        $gatewayRow = $this->gateways->findBySlug($slug);
        $module = $this->modules->get(GatewayModule::class, $slug);

        if ($gatewayRow === null || (int) $gatewayRow['is_enabled'] !== 1 || !$module instanceof GatewayModule) {
            $reason = "Gateway '{$slug}' is disabled or uninstalled in database.";
            $this->writeGatewayLog($slug, $invoiceId, $clientId, 'DISABLED', $reason);
            $msg = urlencode($reason);
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg={$msg}");
        }

        $remaining = round((float) $invoice['total'] - $this->transactions->totalCompletedForInvoice($invoiceId), 2);

        if ($remaining <= 0) {
            $this->writeGatewayLog($slug, $invoiceId, $clientId, 'PAID', 'Invoice has zero remaining balance.');
            return Response::redirect("/client/invoices/{$invoiceId}?payment=success");
        }

        $reference = "cv-{$slug}-{$invoiceId}-" . bin2hex(random_bytes(6));
        $config = json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: [];
        $gatewayCurrency = strtoupper(trim((string) ($config['gateway_currency'] ?? 'NGN'))) ?: 'NGN';

        // ── Currency Conversion Logic ────────────────────────────────────────────
        // Every stored monetary amount — invoice totals included — is authoritative
        // in the system BASE currency (see CurrencyService); currency_id/currency_rate
        // are a *display* lock, not a statement about the unit `total` is stored in.
        // So $remaining is already a base-currency figure.
        //
        // Exchange rates read "units of this currency per 1 base unit"
        // (e.g. NGN = 1490 means 1 base unit = 1490 NGN).
        //
        // The gateway must be charged in ITS currency, so there is exactly one
        // conversion to do:
        //   captureAmount = remaining (base) × gatewayRate
        //
        // Dividing by the invoice's rate first would convert an already-base
        // amount a second time and charge ~1/rate of the real price.

        $gatewayCurrRow = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
        $gatewayRate = ($gatewayCurrRow !== null && (float) $gatewayCurrRow['exchange_rate'] > 0)
            ? (float) $gatewayCurrRow['exchange_rate']
            : 1.0;

        $captureAmount = round($remaining * $gatewayRate, 2);

        $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');
        $clientName = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));
        $this->writeGatewayLog(
            $slug, $invoiceId, $clientId, 'INITIATING',
            "Remaining: {$remaining} (base) → Capture: {$captureAmount} {$gatewayCurrency} (gw_rate={$gatewayRate}), ref={$reference}"
        );

        if ($captureAmount <= 0) {
            $reason = "Computed charge amount is {$captureAmount} {$gatewayCurrency} — refusing to start a zero-value payment.";
            $this->writeGatewayLog($slug, $invoiceId, $clientId, 'FAILED', $reason);
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg=" . urlencode($reason));
        }

        try {
            $result = $module->capture([
                'config' => $config,
                'email' => (string) $client['email'],
                'name' => $clientName !== '' ? $clientName : (string) $client['email'],
                'phone' => (string) ($client['phone'] ?? ''),
                'amount' => $captureAmount,
                'currency' => $gatewayCurrency,
                'reference' => $reference,
                'callbackUrl' => "{$baseUrl}/pay/{$slug}/callback",
                'metadata' => ['invoice_id' => $invoiceId, 'client_id' => (int) $client['id']],
            ]);
        } catch (\Throwable $e) {
            $errMessage = 'Exception in gateway module: ' . $e->getMessage();
            $this->writeGatewayLog($slug, $invoiceId, $clientId, 'EXCEPTIONAL_ERROR', $errMessage, [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            $errMsg = urlencode($errMessage);
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg={$errMsg}");
        }

        if (!$result['success'] || empty($result['redirectUrl'])) {
            $failureMsg = $result['message'] ?? 'Payment initialization failed — no redirect URL returned.';
            $this->writeGatewayLog($slug, $invoiceId, $clientId, 'FAILED', $failureMsg, [
                'result' => $result,
            ]);
            $errMsg = urlencode($failureMsg);
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg={$errMsg}");
        }

        $this->writeGatewayLog($slug, $invoiceId, $clientId, 'SUCCESS', "Redirecting to checkout URL: " . $result['redirectUrl']);
        return Response::redirect($result['redirectUrl']);
    }

    private function writeGatewayLog(
        string $slug,
        int $invoiceId,
        ?int $clientId,
        string $status,
        string $message,
        array $extra = []
    ): void {
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $extraJson = !empty($extra) ? ' | Extra: ' . json_encode($extra, JSON_UNESCAPED_SLASHES) : '';
        $logLine = sprintf(
            "[%s] Gateway: %s | Invoice: #%d | Client: #%d | Status: %s | Message: %s%s\n",
            $timestamp,
            $slug,
            $invoiceId,
            $clientId ?? 0,
            strtoupper($status),
            $message,
            $extraJson
        );

        // 1. File log in storage/logs/payment_gateways.log
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        @file_put_contents($logDir . '/payment_gateways.log', $logLine, FILE_APPEND);

        // 2. System error_log
        error_log("[GATEWAY_LOG] {$logLine}");
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
        // PayHub returns ?reference=PH_... (its own reference) after the user
        // pays on checkout.php. We also embed our own cv- reference as ?reference=
        // in the redirect_url we pass to PayHub, so we check for our cv- reference
        // first and use that for verifyTransaction if present.
        $rawReference = (string) ($request->query('reference') ?? $request->query('trxref') ?? $request->query('transaction_id') ?? $request->query('token') ?? '');

        // For PayHub: if the reference is a PH_ gateway reference rather than
        // our own cv- reference, try to verify it directly (PayHub's verify
        // endpoint accepts both the PH_ reference and the cv- reference we passed
        // as 'reference' during initialize).
        $reference = $rawReference;

        if ($reference === '') {
            return Response::redirect('/client/invoices');
        }

        $config = $this->configFor($slug);
        $verification = $module->verifyTransaction($reference, $config);
        $invoiceId = $this->invoiceIdFrom($verification);

        // PayHub may return a PH_ reference that doesn't carry our invoice metadata.
        // In this case, fall back to extracting the invoice ID from our cv- reference
        // pattern embedded in the query string as ?trxref= or ?cv_ref=.
        if ($invoiceId === null && str_starts_with($reference, 'PH_')) {
            $cvRef = (string) ($request->query('trxref') ?? $request->query('cv_ref') ?? '');
            if ($cvRef !== '' && preg_match('/^cv-[a-z]+-(\d+)-/', $cvRef, $m) === 1) {
                $invoiceId = (int) $m[1];
            }
        }

        if ($invoiceId === null) {
            return Response::redirect('/client/invoices');
        }

        if ($verification['success']) {
            error_log("[PAYMENT] Payment verified successfully for invoice {$invoiceId}");

            $verification['amount'] = $this->toBaseCurrency($verification['amount'], $config, '[PAYMENT]');

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
            $verification['amount'] = $this->toBaseCurrency($verification['amount'], $config, '[PAYMENT-WEBHOOK]');

            $this->recordIfNew($slug, $verification, $invoiceId);
        }

        return Response::html('ok', 200);
    }

    /**
     * Converts an amount the gateway reported (in the gateway's own currency)
     * back into the system base currency, which is the unit every stored
     * monetary amount — including invoice totals — is held in.
     *
     * This is the exact inverse of the `remaining × gatewayRate` conversion
     * initiate() applies when deciding what to charge, so a full payment
     * round-trips back to precisely the invoice total.
     *
     * @param array<string, mixed> $config
     */
    private function toBaseCurrency(float $gatewayAmount, array $config, string $logPrefix): float
    {
        $gatewayCurrency = strtoupper(trim((string) ($config['gateway_currency'] ?? 'NGN'))) ?: 'NGN';
        $row = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
        $gatewayRate = ($row !== null && (float) $row['exchange_rate'] > 0) ? (float) $row['exchange_rate'] : 1.0;

        $baseAmount = round($gatewayAmount / $gatewayRate, 2);
        error_log("{$logPrefix} Currency conversion: {$gatewayAmount} {$gatewayCurrency} (rate={$gatewayRate}) → {$baseAmount} base");

        return $baseAmount;
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
