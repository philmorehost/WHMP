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

        $prepared = $this->prepareCharge($slug, $invoiceId);

        if ($prepared['error'] !== null) {
            return match ($prepared['status']) {
                'unauthenticated' => Response::redirect('/client/login'),
                'not_found' => Response::html('404 Not Found', 404),
                'settled' => Response::redirect("/client/invoices/{$invoiceId}?payment=success"),
                default => Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg=" . urlencode($prepared['error'])),
            };
        }

        $client = $prepared['client'];
        $module = $prepared['module'];
        $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');
        $clientName = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));

        try {
            $result = $module->capture([
                'config' => $prepared['config'],
                'email' => (string) $client['email'],
                'name' => $clientName !== '' ? $clientName : (string) $client['email'],
                'phone' => (string) ($client['phone'] ?? ''),
                'amount' => $prepared['amount'],
                'currency' => $prepared['currency'],
                'reference' => $prepared['reference'],
                'callbackUrl' => "{$baseUrl}/pay/{$slug}/callback",
                'metadata' => ['invoice_id' => $invoiceId, 'client_id' => (int) $client['id']],
            ]);
        } catch (\Throwable $e) {
            $errMessage = 'Exception in gateway module: ' . $e->getMessage();
            $this->writeGatewayLog($slug, $invoiceId, (int) $client['id'], 'EXCEPTIONAL_ERROR', $errMessage, [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            $errMsg = urlencode($errMessage);
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg={$errMsg}");
        }

        if (!$result['success'] || empty($result['redirectUrl'])) {
            $failureMsg = $result['message'] ?? 'Payment initialization failed — no redirect URL returned.';
            $this->writeGatewayLog($slug, $invoiceId, (int) $client['id'], 'FAILED', $failureMsg, [
                'result' => $result,
            ]);
            $errMsg = urlencode($failureMsg);
            return Response::redirect("/client/invoices/{$invoiceId}?payment=error&msg={$errMsg}");
        }

        $this->writeGatewayLog($slug, $invoiceId, (int) $client['id'], 'SUCCESS', "Redirecting to checkout URL: " . $result['redirectUrl']);
        return Response::redirect($result['redirectUrl']);
    }

    /**
     * JSON init for gateways rendered as an on-page popup rather than an
     * off-site redirect (PayHub's inline checkout today).
     *
     * The popup is handed *only* values the server decided: the reference and
     * the charge amount are computed here by the same prepareCharge() the
     * redirect flow uses, so a tampered page cannot invent a cheaper amount or
     * reuse someone else's reference. Nothing here marks anything paid —
     * settlement still goes through /pay/{gateway}/callback, which verifies the
     * transaction server-side against the gateway before a single naira is
     * recorded. The public key is safe to expose; it is publishable by design.
     */
    public function initiateInline(Request $request, array $params): Response
    {
        $slug = (string) ($params['gateway'] ?? 'unknown');
        $invoiceId = (int) ($params['id'] ?? 0);

        $prepared = $this->prepareCharge($slug, $invoiceId);

        if ($prepared['error'] !== null) {
            $httpStatus = match ($prepared['status']) {
                'unauthenticated' => 401,
                'not_found' => 404,
                'settled' => 200,
                default => 422,
            };

            return $this->json([
                'success' => false,
                'settled' => $prepared['status'] === 'settled',
                'message' => $prepared['error'],
                'redirectUrl' => "/client/invoices/{$invoiceId}?payment=" . ($prepared['status'] === 'settled' ? 'success' : 'error'),
            ], $httpStatus);
        }

        $client = $prepared['client'];
        $publicKey = (string) ($prepared['config']['public_key'] ?? '');

        if ($publicKey === '') {
            $reason = 'Inline checkout needs a Public Key — set it in Admin → Gateways, or use the redirect flow.';
            $this->writeGatewayLog($slug, $invoiceId, (int) $client['id'], 'FAILED', $reason);

            return $this->json(['success' => false, 'message' => $reason], 422);
        }

        // The popup still has to be backed by a real transaction on the
        // gateway. PayHub's checkout looks the reference up and, finding
        // nothing, would fall back to whatever amount sat in the query string;
        // worse, its verify endpoint matches on (reference, merchant) and 404s
        // for a reference it never issued — so a payment made against a
        // self-minted reference could never be confirmed, and the invoice would
        // never settle. Initialising here means the gateway knows the amount
        // and owns the reference before the client ever sees a card form.
        $clientName = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));
        $baseUrl = rtrim((string) $this->config->env('APP_URL', ''), '/');

        try {
            $result = $prepared['module']->capture([
                'config' => $prepared['config'],
                'email' => (string) $client['email'],
                'name' => $clientName !== '' ? $clientName : (string) $client['email'],
                'phone' => (string) ($client['phone'] ?? ''),
                'amount' => $prepared['amount'],
                'currency' => $prepared['currency'],
                'reference' => $prepared['reference'],
                'callbackUrl' => "{$baseUrl}/pay/{$slug}/callback",
                'metadata' => ['invoice_id' => $invoiceId, 'client_id' => (int) $client['id']],
            ]);
        } catch (\Throwable $e) {
            $message = 'Exception in gateway module: ' . $e->getMessage();
            $this->writeGatewayLog($slug, $invoiceId, (int) $client['id'], 'EXCEPTIONAL_ERROR', $message, [
                'exception' => get_class($e),
            ]);

            return $this->json(['success' => false, 'message' => $message], 502);
        }

        if (!$result['success']) {
            $message = $result['message'] ?? 'Could not start the payment.';
            $this->writeGatewayLog($slug, $invoiceId, (int) $client['id'], 'FAILED', $message, ['result' => $result]);

            return $this->json(['success' => false, 'message' => $message], 502);
        }

        // Use the reference the GATEWAY issued, not ours — PayHub mints its own
        // PH_… reference and ignores the one we send, and that is the only one
        // its checkout and verify endpoints recognise. Our own reference rides
        // along as trxref purely so the callback can still recover the invoice
        // id if the gateway does not echo our metadata back.
        $gatewayReference = (string) ($result['transactionId'] ?? $prepared['reference']);

        $this->writeGatewayLog(
            $slug, $invoiceId, (int) $client['id'], 'SUCCESS',
            "Inline checkout initialised, gateway ref={$gatewayReference}"
        );

        return $this->json([
            'success' => true,
            'publicKey' => $publicKey,
            'reference' => $gatewayReference,
            'amount' => $prepared['amount'],
            'currency' => $prepared['currency'],
            'email' => (string) $client['email'],
            // Where the browser should land once the popup closes with a
            // result — the server-side verification endpoint, never a page
            // that would treat the popup's own word as proof of payment.
            'callbackUrl' => '/pay/' . rawurlencode($slug) . '/callback'
                . '?reference=' . rawurlencode($gatewayReference)
                . '&trxref=' . rawurlencode($prepared['reference']),
        ]);
    }

    /**
     * Everything that must be true, and every figure that must be decided,
     * before a client can be sent to a gateway — shared by the redirect flow
     * and the inline popup so the two cannot disagree about who may pay, how
     * much, or under which reference.
     *
     * @return array{error: ?string, status: string, client: array<string, mixed>|null, module: GatewayModule|null, config: array<string, mixed>, reference: string, amount: float, currency: string}
     */
    private function prepareCharge(string $slug, int $invoiceId): array
    {
        $fail = static fn (string $status, string $error): array => [
            'error' => $error, 'status' => $status, 'client' => null, 'module' => null,
            'config' => [], 'reference' => '', 'amount' => 0.0, 'currency' => '',
        ];

        $client = $this->guard->currentClient();

        if ($client === null) {
            $this->writeGatewayLog($slug, $invoiceId, null, 'FAILED', 'Client not authenticated.');
            return $fail('unauthenticated', 'Please sign in to pay this invoice.');
        }

        $clientId = (int) $client['id'];
        $invoice = $this->invoices->find($invoiceId);

        if ($invoice === null || (int) $invoice['client_id'] !== $clientId) {
            $this->writeGatewayLog($slug, $invoiceId, $clientId, 'FAILED', 'Invoice not found or permission denied.');
            return $fail('not_found', 'Invoice not found.');
        }

        $gatewayRow = $this->gateways->findBySlug($slug);
        $module = $this->modules->get(GatewayModule::class, $slug);

        if ($gatewayRow === null || (int) $gatewayRow['is_enabled'] !== 1 || !$module instanceof GatewayModule) {
            $reason = "Gateway '{$slug}' is disabled or uninstalled in database.";
            $this->writeGatewayLog($slug, $invoiceId, $clientId, 'DISABLED', $reason);
            return $fail('disabled', $reason);
        }

        $remaining = round((float) $invoice['total'] - $this->transactions->totalCompletedForInvoice($invoiceId), 2);

        if ($remaining <= 0) {
            $this->writeGatewayLog($slug, $invoiceId, $clientId, 'PAID', 'Invoice has zero remaining balance.');
            return $fail('settled', 'This invoice is already paid.');
        }

        $config = json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: [];
        $gatewayCurrency = strtoupper(trim((string) ($config['gateway_currency'] ?? 'NGN'))) ?: 'NGN';
        $reference = "cv-{$slug}-{$invoiceId}-" . bin2hex(random_bytes(6));

        // ── Currency Conversion ──────────────────────────────────────────────
        // Every stored monetary amount — invoice totals included — is
        // authoritative in the system BASE currency (see CurrencyService);
        // currency_id/currency_rate are a *display* lock, not a statement about
        // the unit `total` is stored in. So $remaining is already base.
        //
        // Rates read "units of this currency per 1 base unit" (NGN = 1490 means
        // 1 base unit = 1490 NGN), and the gateway must be charged in ITS
        // currency, so there is exactly one conversion to do. Dividing by the
        // invoice's own rate first would convert an already-base amount twice
        // and charge roughly 1/rate of the real price.
        $gatewayCurrRow = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
        $gatewayRate = ($gatewayCurrRow !== null && (float) $gatewayCurrRow['exchange_rate'] > 0)
            ? (float) $gatewayCurrRow['exchange_rate']
            : 1.0;

        $captureAmount = round($remaining * $gatewayRate, 2);

        $this->writeGatewayLog(
            $slug, $invoiceId, $clientId, 'INITIATING',
            "Remaining: {$remaining} (base) → Capture: {$captureAmount} {$gatewayCurrency} (gw_rate={$gatewayRate}), ref={$reference}"
        );

        if ($captureAmount <= 0) {
            $reason = "Computed charge amount is {$captureAmount} {$gatewayCurrency} — refusing to start a zero-value payment.";
            $this->writeGatewayLog($slug, $invoiceId, $clientId, 'FAILED', $reason);
            return $fail('invalid_amount', $reason);
        }

        return [
            'error' => null,
            'status' => 'ok',
            'client' => $client,
            'module' => $module,
            'config' => $config,
            'reference' => $reference,
            'amount' => $captureAmount,
            'currency' => $gatewayCurrency,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return (new Response((string) json_encode($payload), $status))
            ->withHeader('Content-Type', 'application/json');
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
