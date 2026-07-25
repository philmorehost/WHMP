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
        // The system base currency is USD (exchange_rate = 1.0).
        // All currencies in the `currencies` table store their exchange rate as:
        //   "how many units of this currency = 1 USD"
        //   e.g. NGN = 1490 means 1 USD = 1490 NGN.
        //
        // invoice['total'] is stored in the INVOICE's own currency (e.g. NGN).
        // NOTE: invoice['currency_rate'] is not reliable (stored as 1.0 by
        // add-funds flow), so we always look up the live rate from the currencies table.
        //
        // Correct formula:
        //   1. remaining (in invoice currency) → USD base = remaining / invoiceCurrencyRate
        //   2. USD base → gateway currency amount = usdBase × gatewayCurrencyRate
        //
        // Same-currency case: invoiceCurrencyRate = gatewayCurrencyRate → divide and
        // multiply cancel out → captureAmount = remaining exactly. ✓

        // 1. Get invoice currency's exchange rate (units per USD) from currencies table
        $invoiceCurrencyId = $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null;
        $invoiceCurrRow = $invoiceCurrencyId !== null
            ? $this->db->selectOne('SELECT code, exchange_rate FROM currencies WHERE id = ?', [$invoiceCurrencyId])
            : null;
        $invoiceCurrCode = $invoiceCurrRow['code'] ?? 'USD';
        $invoiceCurrRate = ($invoiceCurrRow !== null && (float) $invoiceCurrRow['exchange_rate'] > 0)
            ? (float) $invoiceCurrRow['exchange_rate']
            : 1.0;

        // 3. Get gateway currency's exchange rate (units per USD) from currencies table
        $gatewayCurrRow = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
        $gatewayRate = ($gatewayCurrRow !== null && (float) $gatewayCurrRow['exchange_rate'] > 0)
            ? (float) $gatewayCurrRow['exchange_rate']
            : 1.0;

        // 4. Calculate capture amount: if same currency, send directly; if different, convert via USD
        if ($invoiceCurrCode === $gatewayCurrency) {
            // Same currency — no conversion needed
            $captureAmount = round($remaining, 2);
        } else {
            // Different currencies — convert through USD
            $usdBase = $remaining / $invoiceCurrRate;
            $captureAmount = round($usdBase * $gatewayRate, 2);
        }

        $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');
        $clientName = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));
        $this->writeGatewayLog(
            $slug, $invoiceId, $clientId, 'INITIATING',
            "Remaining: {$remaining} {$invoiceCurrCode} → Capture: {$captureAmount} {$gatewayCurrency} (same_currency=" . ($invoiceCurrCode === $gatewayCurrency ? 'yes' : 'no') . ", inv_rate={$invoiceCurrRate}, gw_rate={$gatewayRate}), ref={$reference}"
        );

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

            $invoice = $this->invoices->find($invoiceId);
            if ($invoice !== null) {
                // The gateway may have returned payment in a different currency than the invoice.
                // Convert the payment amount from gateway currency to invoice currency so it
                // matches invoice['total'] exactly. Both are then in the same currency when
                // PaymentService::recordPayment() compares totalPaid >= invoice['total'].
                $gatewayCurrency = strtoupper(trim((string) ($config['gateway_currency'] ?? 'NGN'))) ?: 'NGN';
                $invoiceCurrencyId = $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null;

                // Convert to invoice currency only if needed (and only if we can determine it)
                if ($invoiceCurrencyId !== null) {
                    $invoiceCurrRow = $this->db->selectOne('SELECT code, exchange_rate FROM currencies WHERE id = ?', [$invoiceCurrencyId]);

                    if ($invoiceCurrRow !== null) {
                        $invoiceCurrCode = (string) ($invoiceCurrRow['code'] ?? '');

                        if ($invoiceCurrCode !== '' && $invoiceCurrCode !== $gatewayCurrency) {
                            // Invoice and gateway in different currencies — convert payment to invoice currency
                            $gatewayRow = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
                            $gatewayRate = $gatewayRow !== null ? (float) $gatewayRow['exchange_rate'] : 1.0;
                            $invoiceRate = (float) ($invoiceCurrRow['exchange_rate'] ?? 1.0);

                            if ($gatewayRate > 0 && $invoiceRate > 0) {
                                $originalAmount = $verification['amount'];
                                $usdBase = $verification['amount'] / $gatewayRate;
                                $verification['amount'] = $usdBase * $invoiceRate;
                                error_log("[PAYMENT] Currency conversion: {$originalAmount} {$gatewayCurrency} → {$verification['amount']} {$invoiceCurrCode}");
                            }
                        }
                    }
                }
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
            $invoice = $this->invoices->find($invoiceId);
            if ($invoice !== null) {
                // Convert webhook payment amount from gateway currency to invoice currency
                // so it can be correctly compared against the invoice total in the same currency.
                $gatewayCurrency = strtoupper(trim((string) ($config['gateway_currency'] ?? 'NGN'))) ?: 'NGN';
                $invoiceCurrencyId = $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null;

                if ($invoiceCurrencyId !== null) {
                    $invoiceCurrRow = $this->db->selectOne('SELECT code, exchange_rate FROM currencies WHERE id = ?', [$invoiceCurrencyId]);

                    if ($invoiceCurrRow !== null) {
                        $invoiceCurrCode = (string) ($invoiceCurrRow['code'] ?? '');

                        if ($invoiceCurrCode !== '' && $invoiceCurrCode !== $gatewayCurrency) {
                            $gatewayRow = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
                            $gatewayRate = $gatewayRow !== null ? (float) $gatewayRow['exchange_rate'] : 1.0;
                            $invoiceRate = (float) ($invoiceCurrRow['exchange_rate'] ?? 1.0);

                            if ($gatewayRate > 0 && $invoiceRate > 0) {
                                $originalAmount = $verification['amount'];
                                $usdBase = $verification['amount'] / $gatewayRate;
                                $verification['amount'] = $usdBase * $invoiceRate;
                                error_log("[PAYMENT-WEBHOOK] Currency conversion: {$originalAmount} {$gatewayCurrency} → {$verification['amount']} {$invoiceCurrCode}");
                            }
                        }
                    }
                }
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
