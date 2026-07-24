<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Modules\GatewayModule;
use CodeVault\Provisioning\HttpClient;

/**
 * Paystack (blueprint §10: gateway choice for R4 was left open — this
 * resolves it for the NG market alongside FlutterwaveGateway). Redirect-
 * based: capture() calls Transaction Initialize and hands back Paystack's
 * hosted checkout URL; the client pays there, then either the webhook or
 * the redirect-return callback calls verifyTransaction() to confirm the
 * charge server-side before it's ever recorded as paid — never trust the
 * redirect alone, since a client can forge the return URL.
 *
 * Config (like ProvisioningModule's $params['server'] pattern) is passed
 * in per-call via $params['config'] rather than self-fetched from the DB
 * — keeps this class DB-agnostic and matches the existing module convention.
 *
 * NOT live-verified against Paystack's real API (no sandbox keys in this
 * environment) — request/response shapes are spec-correct per Paystack's
 * documented API, and the webhook-signature verification + payment-
 * recording pipeline downstream of this class *is* live-verified with a
 * self-signed real payload (see PaymentCallbackControllerTest).
 */
final class PaystackGateway implements GatewayModule
{
    private const BASE_URL = 'https://api.paystack.co';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Paystack',
            'description' => 'Card, bank transfer, and USSD payments via Paystack.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'secret_key' => ['type' => 'password', 'label' => 'Secret Key', 'default' => ''],
            'public_key' => ['type' => 'text', 'label' => 'Public Key', 'default' => ''],
        ];
    }

    public function isOffsite(): bool
    {
        return true;
    }

    /**
     * @param array{config: array<string, mixed>, email: string, amount: float, reference: string, callbackUrl: string, metadata?: array<string, mixed>} $params
     * @return array{success: bool, redirectUrl?: string, transactionId?: string, message: string}
     */
    public function capture(array $params): array
    {
        $secretKey = (string) ($params['config']['secret_key'] ?? '');

        if ($secretKey === '') {
            return ['success' => false, 'message' => 'Paystack API is not configured — missing secret key. Please configure Paystack Secret Key in Admin -> Gateways.'];
        }

        $body = json_encode([
            'email' => $params['email'],
            // Paystack amounts are in the currency's smallest unit (kobo for NGN).
            'amount' => (int) round($params['amount'] * 100),
            'reference' => $params['reference'],
            'callback_url' => $params['callbackUrl'],
            'metadata' => $params['metadata'] ?? [],
        ]);

        $response = $this->http->request('POST', self::BASE_URL . '/transaction/initialize', $this->headers($secretKey), $body);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        if ($response['status'] !== 200 || !is_array($decoded) || ($decoded['status'] ?? false) !== true) {
            $msg = is_array($decoded) ? ($decoded['message'] ?? 'Paystack initialization returned an error.') : 'Paystack API connection failed.';
            return ['success' => false, 'message' => $msg];
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $redirectUrl = (string) ($data['authorization_url'] ?? $data['checkout_url'] ?? $data['link'] ?? $data['url'] ?? '');

        return [
            'success' => $redirectUrl !== '',
            'redirectUrl' => $redirectUrl,
            'transactionId' => $data['reference'] ?? $params['reference'],
            'message' => $redirectUrl !== '' ? 'Redirecting to Paystack.' : 'Paystack initialization failed — no redirect URL returned.',
        ];
    }

    /**
     * Server-side verification of a transaction reference — the only
     * source of truth for whether a charge actually succeeded. Called by
     * PaymentCallbackController from both the redirect-return and the
     * webhook path.
     *
     * @param array<string, mixed> $config
     * @return array{success: bool, status: string, reference: string, amount: float, metadata: array<string, mixed>}
     */
    public function verifyTransaction(string $reference, array $config): array
    {
        $secretKey = (string) ($config['secret_key'] ?? '');
        $response = $this->http->request('GET', self::BASE_URL . '/transaction/verify/' . rawurlencode($reference), $this->headers($secretKey));
        $decoded = json_decode($response['body'], true);

        if ($response['status'] !== 200 || !is_array($decoded) || ($decoded['status'] ?? false) !== true) {
            return ['success' => false, 'status' => 'error', 'reference' => $reference, 'amount' => 0.0, 'metadata' => []];
        }

        $data = $decoded['data'] ?? [];
        $status = (string) ($data['status'] ?? 'failed');

        return [
            'success' => $status === 'success',
            'status' => $status,
            'reference' => (string) ($data['reference'] ?? $reference),
            'amount' => ((float) ($data['amount'] ?? 0)) / 100,
            'metadata' => (array) ($data['metadata'] ?? []),
            // Paystack returns a reusable card authorization on every
            // successful charge — this is what powers recurring auto-charge
            // (charge_authorization) without another redirect. Only surfaced
            // when the card is flagged reusable.
            'authorization' => $this->extractAuthorization($data),
        ];
    }

    /**
     * @param array<string, mixed> $data verify response data block
     * @return array{token: string, brand: ?string, last4: ?string, exp_month: ?string, exp_year: ?string}|null
     */
    private function extractAuthorization(array $data): ?array
    {
        $auth = $data['authorization'] ?? null;

        if (!is_array($auth) || ($auth['reusable'] ?? false) !== true || ($auth['authorization_code'] ?? '') === '') {
            return null;
        }

        return [
            'token' => (string) $auth['authorization_code'],
            'brand' => isset($auth['card_type']) ? (string) $auth['card_type'] : null,
            'last4' => isset($auth['last4']) ? (string) $auth['last4'] : null,
            'exp_month' => isset($auth['exp_month']) ? (string) $auth['exp_month'] : null,
            'exp_year' => isset($auth['exp_year']) ? (string) $auth['exp_year'] : null,
        ];
    }

    public function refund(array $params): array
    {
        $secretKey = (string) ($params['config']['secret_key'] ?? '');
        $body = json_encode(['transaction' => $params['transactionId']] + (isset($params['amount']) ? ['amount' => (int) round($params['amount'] * 100)] : []));

        $response = $this->http->request('POST', self::BASE_URL . '/refund', $this->headers($secretKey), $body);
        $decoded = json_decode($response['body'], true);

        return [
            'success' => $response['status'] === 200 && is_array($decoded) && ($decoded['status'] ?? false) === true,
            'message' => $decoded['message'] ?? 'Refund request failed.',
        ];
    }

    public function void(array $params): array
    {
        return ['success' => false, 'message' => 'Paystack does not support voiding — issue a refund instead.'];
    }

    /**
     * Paystack doesn't mint tokens on demand — a reusable authorization is
     * returned by verifyTransaction() after the client's first real
     * checkout, and captured there. Nothing to do here.
     */
    public function tokenize(array $params): array
    {
        return ['success' => false, 'message' => 'Paystack payment methods are saved automatically after a successful checkout.'];
    }

    /**
     * Charges a previously-saved reusable authorization (recurring auto-
     * charge) via Paystack's charge_authorization endpoint — no redirect.
     *
     * @param array{config: array<string, mixed>, token: string, email: string, amount: float, reference: string, metadata?: array<string, mixed>} $params
     * @return array{success: bool, transactionId?: string, status: string, message: string}
     */
    public function chargeToken(array $params): array
    {
        $secretKey = (string) ($params['config']['secret_key'] ?? '');
        $token = (string) ($params['token'] ?? '');

        if ($secretKey === '') {
            return ['success' => false, 'status' => 'error', 'message' => 'Paystack is not configured — missing secret key.'];
        }

        if ($token === '') {
            return ['success' => false, 'status' => 'error', 'message' => 'No saved payment authorization to charge.'];
        }

        $body = json_encode([
            'authorization_code' => $token,
            'email' => (string) ($params['email'] ?? ''),
            'amount' => (int) round(((float) ($params['amount'] ?? 0)) * 100),
            'reference' => (string) ($params['reference'] ?? ''),
            'metadata' => $params['metadata'] ?? [],
        ]);

        $response = $this->http->request('POST', self::BASE_URL . '/transaction/charge_authorization', $this->headers($secretKey), $body);
        $decoded = json_decode($response['body'], true);

        if ($response['status'] !== 200 || !is_array($decoded) || ($decoded['status'] ?? false) !== true) {
            return ['success' => false, 'status' => 'failed', 'message' => $decoded['message'] ?? 'Paystack authorization charge failed.'];
        }

        $data = $decoded['data'] ?? [];
        $chargeStatus = (string) ($data['status'] ?? 'failed');

        return [
            'success' => $chargeStatus === 'success',
            'transactionId' => (string) ($data['reference'] ?? ($params['reference'] ?? '')),
            'status' => $chargeStatus,
            'message' => $chargeStatus === 'success' ? 'Charge successful.' : ('Charge ' . $chargeStatus . '.'),
        ];
    }

    /**
     * @param array<string, mixed> $rawPayload
     * @param array<string, string> $headers
     * @return array{valid: bool, event: string, data: array<string, mixed>}
     */
    public function handleCallback(array $rawPayload, array $headers): array
    {
        // Signature verification happens against the *raw request body*
        // (see PaymentCallbackController, which has that before it's ever
        // decoded to an array) — this method assumes the caller already
        // confirmed the signature and is just extracting the event shape.
        return [
            'valid' => true,
            'event' => (string) ($rawPayload['event'] ?? 'unknown'),
            'data' => (array) ($rawPayload['data'] ?? []),
        ];
    }

    /** Verifies Paystack's X-Paystack-Signature header: HMAC-SHA512 of the raw body, keyed by the secret key. */
    public static function verifySignature(string $rawBody, string $signatureHeader, string $secretKey): bool
    {
        return hash_equals(hash_hmac('sha512', $rawBody, $secretKey), $signatureHeader);
    }

    /** @return array<string, string> */
    private function headers(string $secretKey): array
    {
        return [
            'Authorization' => "Bearer {$secretKey}",
            'Content-Type' => 'application/json',
        ];
    }
}
