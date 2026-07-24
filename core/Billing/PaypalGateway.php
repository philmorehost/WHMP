<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Modules\GatewayModule;
use CodeVault\Provisioning\HttpClient;

/**
 * PayPal (Orders v2 API) — redirect-based like Paystack/Flutterwave:
 * capture() creates a PayPal order and hands back its "approve" link, the
 * client approves on PayPal's own site, then the redirect-return or
 * webhook path captures the order and verifies it server-side before
 * ever recording it as paid.
 *
 * Unlike the other gateways here, PayPal's webhook signature isn't a
 * local HMAC of the raw body against a shared secret — PayPal requires
 * calling its own `/v1/notifications/verify-webhook-signature` endpoint
 * with the PAYPAL-TRANSMISSION-* headers, the configured webhook_id, and
 * the raw event body, which needs network access. That's why
 * verifySignature() here is an instance method (needs $this->http and an
 * OAuth token), not the static one-liner the other gateways use —
 * PaymentCallbackController special-cases this by instanceof-checking
 * for PaypalGateway rather than calling a shared interface method.
 *
 * Config (like ProvisioningModule's $params['server'] pattern) is passed
 * in per-call via $params['config'] rather than self-fetched from the DB
 * — keeps this class DB-agnostic and matches the existing module convention.
 *
 * NOT live-verified against PayPal's real API (no sandbox credentials in
 * this environment) — request/response shapes are spec-correct per
 * PayPal's documented Orders v2 + Webhooks API, same caveat every other
 * gateway module in this codebase already carries.
 */
final class PaypalGateway implements GatewayModule
{
    private const LIVE_BASE_URL = 'https://api-m.paypal.com';
    private const SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'PayPal',
            'description' => 'PayPal Checkout via the Orders v2 API.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'client_id' => ['type' => 'text', 'label' => 'Client ID', 'default' => ''],
            'client_secret' => ['type' => 'password', 'label' => 'Client Secret', 'default' => ''],
            'webhook_id' => ['type' => 'text', 'label' => 'Webhook ID', 'default' => ''],
            'sandbox' => ['type' => 'text', 'label' => 'Sandbox Mode (1 = sandbox, blank = live)', 'default' => ''],
        ];
    }

    public function isOffsite(): bool
    {
        return true;
    }

    /**
     * @param array{config: array<string, mixed>, email: string, amount: float, currency?: string, reference: string, callbackUrl: string, metadata?: array<string, mixed>} $params
     * @return array{success: bool, redirectUrl?: string, transactionId?: string, message: string}
     */
    public function capture(array $params): array
    {
        $config = (array) ($params['config'] ?? []);
        $token = $this->accessToken($config);

        if ($token === null) {
            return ['success' => false, 'message' => 'PayPal is not configured — check client ID/secret.'];
        }

        $currency = strtoupper((string) ($params['currency'] ?? 'USD'));
        $invoiceId = (string) ($params['metadata']['invoice_id'] ?? '');

        $body = json_encode([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $params['reference'],
                'custom_id' => $invoiceId,
                'invoice_id' => $params['reference'],
                'amount' => [
                    'currency_code' => $currency,
                    'value' => number_format((float) $params['amount'], 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'return_url' => $params['callbackUrl'],
                'cancel_url' => $params['callbackUrl'],
                'user_action' => 'PAY_NOW',
            ],
        ]);

        $response = $this->http->request('POST', $this->baseUrl($config) . '/v2/checkout/orders', $this->headers($token), $body);
        $decoded = json_decode($response['body'], true);

        if (!in_array($response['status'], [200, 201], true) || !is_array($decoded) || !isset($decoded['id'])) {
            return ['success' => false, 'message' => $this->errorMessage($decoded) ?? 'PayPal order creation failed.'];
        }

        $approveUrl = null;
        foreach ((array) ($decoded['links'] ?? []) as $link) {
            if (in_array(($link['rel'] ?? ''), ['approve', 'payer-action'], true)) {
                $approveUrl = (string) $link['href'];
                break;
            }
        }

        if ($approveUrl === null || $approveUrl === '') {
            return ['success' => false, 'message' => 'PayPal API did not return an approval redirect link.'];
        }

        return [
            'success' => true,
            'redirectUrl' => $approveUrl,
            // PayPal's own order id is what capture/verify need — not our reference — so it rides along as the "reference" from here on.
            'transactionId' => (string) $decoded['id'],
            'message' => 'Redirecting to PayPal.',
        ];
    }

    /**
     * Captures the (already client-approved) order, then reports its
     * status — this is the single source-of-truth step: PayPal orders
     * aren't actually charged until this call succeeds.
     *
     * @param array<string, mixed> $config
     * @return array{success: bool, status: string, reference: string, amount: float, metadata: array<string, mixed>}
     */
    public function verifyTransaction(string $reference, array $config): array
    {
        $token = $this->accessToken($config);

        if ($token === null) {
            return ['success' => false, 'status' => 'error', 'reference' => $reference, 'amount' => 0.0, 'metadata' => []];
        }

        // $reference here is PayPal's order id (see capture()'s transactionId).
        $response = $this->http->request('POST', $this->baseUrl($config) . "/v2/checkout/orders/{$reference}/capture", $this->headers($token));
        $decoded = json_decode($response['body'], true);

        // ORDER_ALREADY_CAPTURED is not a failure — the webhook and the
        // redirect-return path can both reach this for the same order;
        // fetching the order's current state covers that idempotently.
        if (!is_array($decoded) || (($decoded['name'] ?? '') === 'UNPROCESSABLE_ENTITY' && $this->hasIssue($decoded, 'ORDER_ALREADY_CAPTURED'))) {
            $getResponse = $this->http->request('GET', $this->baseUrl($config) . "/v2/checkout/orders/{$reference}", $this->headers($token));
            $decoded = json_decode($getResponse['body'], true);
        }

        if (!is_array($decoded)) {
            return ['success' => false, 'status' => 'error', 'reference' => $reference, 'amount' => 0.0, 'metadata' => []];
        }

        $status = (string) ($decoded['status'] ?? 'FAILED');
        $purchaseUnit = (array) ($decoded['purchase_units'][0] ?? []);
        $captures = (array) ($purchaseUnit['payments']['captures'][0] ?? []);
        $amount = (float) ($captures['amount']['value'] ?? $purchaseUnit['amount']['value'] ?? 0.0);
        $invoiceId = $purchaseUnit['custom_id'] ?? null;

        return [
            'success' => $status === 'COMPLETED',
            'status' => $status,
            'reference' => $reference,
            'amount' => $amount,
            'metadata' => $invoiceId !== null ? ['invoice_id' => $invoiceId] : [],
        ];
    }

    public function refund(array $params): array
    {
        $config = (array) ($params['config'] ?? []);
        $token = $this->accessToken($config);

        if ($token === null) {
            return ['success' => false, 'message' => 'PayPal is not configured — check client ID/secret.'];
        }

        // A refund needs the *capture* id, not the order id — resolve it from the order if only the order id was handed in.
        $captureId = (string) ($params['captureId'] ?? '');
        if ($captureId === '') {
            $order = $this->http->request('GET', $this->baseUrl($config) . '/v2/checkout/orders/' . $params['transactionId'], $this->headers($token));
            $orderData = json_decode($order['body'], true);
            $captureId = (string) ($orderData['purchase_units'][0]['payments']['captures'][0]['id'] ?? '');
        }

        if ($captureId === '') {
            return ['success' => false, 'message' => 'Could not resolve a PayPal capture id to refund.'];
        }

        $body = isset($params['amount']) ? json_encode(['amount' => [
            'value' => number_format((float) $params['amount'], 2, '.', ''),
            'currency_code' => strtoupper((string) ($params['currency'] ?? 'USD')),
        ]]) : null;

        $response = $this->http->request('POST', $this->baseUrl($config) . "/v2/payments/captures/{$captureId}/refund", $this->headers($token), $body ?? '');
        $decoded = json_decode($response['body'], true);

        return [
            'success' => in_array($response['status'], [200, 201], true) && is_array($decoded) && ($decoded['status'] ?? '') === 'COMPLETED',
            'message' => is_array($decoded) ? ($this->errorMessage($decoded) ?? 'Refund completed.') : 'Refund request failed.',
        ];
    }

    public function void(array $params): array
    {
        $config = (array) ($params['config'] ?? []);
        $token = $this->accessToken($config);

        if ($token === null) {
            return ['success' => false, 'message' => 'PayPal is not configured — check client ID/secret.'];
        }

        $response = $this->http->request('POST', $this->baseUrl($config) . '/v2/checkout/orders/' . $params['transactionId'] . '/void', $this->headers($token));

        return ['success' => in_array($response['status'], [200, 204], true), 'message' => $response['status'] === 204 ? 'Order voided.' : 'Void failed.'];
    }

    public function tokenize(array $params): array
    {
        return ['success' => false, 'message' => 'Recurring/vaulted payments are not yet implemented for PayPal.'];
    }

    public function chargeToken(array $params): array
    {
        return ['success' => false, 'message' => 'Recurring/vaulted payments are not yet implemented for PayPal.'];
    }

    /**
     * @param array<string, mixed> $rawPayload
     * @param array<string, string> $headers
     * @return array{valid: bool, event: string, data: array<string, mixed>}
     */
    public function handleCallback(array $rawPayload, array $headers): array
    {
        // Signature verification for PayPal happens in verifySignature()
        // below (it needs a live API call, unlike the other gateways'
        // local HMAC check) — by the time this runs the caller already
        // confirmed it. PayPal's order id rides in resource.id for a
        // CHECKOUT.ORDER.APPROVED event, or resource.supplementary_data
        // for a PAYMENT.CAPTURE.* event; either way it's what
        // verifyTransaction() expects as its $reference.
        $resource = (array) ($rawPayload['resource'] ?? []);
        $orderId = $resource['id'] ?? ($resource['supplementary_data']['related_ids']['order_id'] ?? null);

        return [
            'valid' => true,
            'event' => (string) ($rawPayload['event_type'] ?? 'unknown'),
            'data' => ['reference' => $orderId, 'raw' => $rawPayload],
        ];
    }

    /**
     * Calls PayPal's verify-webhook-signature API — the documented way to
     * confirm a webhook actually came from PayPal, since (unlike
     * Paystack/Flutterwave/Payhub) there's no shared-secret HMAC to check
     * locally. Requires the PAYPAL-TRANSMISSION-ID/TIME/SIG,
     * PAYPAL-CERT-URL, and PAYPAL-AUTH-ALGO headers PayPal sends with
     * every webhook delivery, plus the webhook_id configured here
     * (from PayPal's own webhook setup page) and the raw event body.
     *
     * @param array<string, string> $headers as captured by Request::headers() — uppercase, hyphenated (e.g. "PAYPAL-TRANSMISSION-ID")
     * @param array<string, mixed> $config
     */
    public function verifySignature(string $rawBody, array $headers, array $config): bool
    {
        $token = $this->accessToken($config);
        $webhookId = (string) ($config['webhook_id'] ?? '');

        if ($token === null || $webhookId === '') {
            return false;
        }

        $event = json_decode($rawBody, true);

        if (!is_array($event)) {
            return false;
        }

        $body = json_encode([
            'transmission_id' => $headers['PAYPAL-TRANSMISSION-ID'] ?? '',
            'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? '',
            'cert_url' => $headers['PAYPAL-CERT-URL'] ?? '',
            'auth_algo' => $headers['PAYPAL-AUTH-ALGO'] ?? '',
            'transmission_sig' => $headers['PAYPAL-TRANSMISSION-SIG'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);

        $response = $this->http->request('POST', $this->baseUrl($config) . '/v1/notifications/verify-webhook-signature', $this->headers($token), $body);
        $decoded = json_decode($response['body'], true);

        return $response['status'] === 200 && is_array($decoded) && ($decoded['verification_status'] ?? '') === 'SUCCESS';
    }

    private function baseUrl(array $config): string
    {
        return !empty($config['sandbox']) ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;
    }

    /** @param array<string, mixed> $config */
    private function accessToken(array $config): ?string
    {
        $clientId = (string) ($config['client_id'] ?? '');
        $clientSecret = (string) ($config['client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $response = $this->http->request(
            'POST',
            $this->baseUrl($config) . '/v1/oauth2/token',
            ['Authorization' => 'Basic ' . base64_encode("{$clientId}:{$clientSecret}"), 'Content-Type' => 'application/x-www-form-urlencoded'],
            'grant_type=client_credentials'
        );

        $decoded = json_decode($response['body'], true);

        return $response['status'] === 200 && is_array($decoded) && isset($decoded['access_token'])
            ? (string) $decoded['access_token']
            : null;
    }

    /** @param mixed $decoded */
    private function errorMessage($decoded): ?string
    {
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['message'])) {
            return (string) $decoded['message'];
        }

        $firstIssue = $decoded['details'][0]['issue'] ?? null;

        return $firstIssue !== null ? (string) $firstIssue : null;
    }

    /** @param array<string, mixed> $decoded */
    private function hasIssue(array $decoded, string $issue): bool
    {
        foreach ((array) ($decoded['details'] ?? []) as $detail) {
            if (($detail['issue'] ?? '') === $issue) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ];
    }
}
