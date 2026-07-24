<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Modules\GatewayModule;
use CodeVault\Provisioning\HttpClient;

/**
 * Flutterwave (blueprint §10: gateway choice for R4 was left open — this
 * resolves it for the NG market alongside PaystackGateway). Same
 * redirect-based shape as Paystack; see PaystackGateway's docblock for
 * the shared design notes (config-per-call, verify-server-side-always,
 * not live-verified against the real API in this environment).
 *
 * One real difference from Paystack worth calling out: Flutterwave's
 * webhook signature isn't an HMAC — it's a plain shared-secret string
 * ("verif-hash") that must exactly match what's configured in the
 * Flutterwave dashboard, compared with hash_equals() for timing safety
 * even though it's not a hash comparison in the cryptographic sense.
 */
final class FlutterwaveGateway implements GatewayModule
{
    private const BASE_URL = 'https://api.flutterwave.com/v3';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Flutterwave',
            'description' => 'Card, bank transfer, mobile money, and USSD payments via Flutterwave.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'secret_key' => ['type' => 'password', 'label' => 'Secret Key', 'default' => ''],
            'public_key' => ['type' => 'text', 'label' => 'Public Key', 'default' => ''],
            'secret_hash' => ['type' => 'password', 'label' => 'Webhook Secret Hash', 'default' => ''],
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
            return ['success' => false, 'message' => 'Flutterwave API is not configured — missing secret key. Please configure Flutterwave Secret Key in Admin -> Gateways.'];
        }

        $body = json_encode([
            'tx_ref' => $params['reference'],
            'amount' => $params['amount'],
            'currency' => $params['currency'] ?? 'NGN',
            'redirect_url' => $params['callbackUrl'],
            'customer' => ['email' => $params['email']],
            'meta' => $params['metadata'] ?? [],
        ]);

        $response = $this->http->request('POST', self::BASE_URL . '/payments', $this->headers($secretKey), $body);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        if ($response['status'] !== 200 || !is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            $msg = is_array($decoded) ? ($decoded['message'] ?? 'Flutterwave initialization returned an error.') : 'Flutterwave API connection failed.';
            return ['success' => false, 'message' => $msg];
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $redirectUrl = (string) ($data['link'] ?? $data['authorization_url'] ?? $data['checkout_url'] ?? $data['url'] ?? '');

        return [
            'success' => $redirectUrl !== '',
            'redirectUrl' => $redirectUrl,
            'transactionId' => $params['reference'],
            'message' => $redirectUrl !== '' ? 'Redirecting to Flutterwave.' : 'Flutterwave initialization failed — no redirect URL returned.',
        ];
    }

    /**
     * Server-side verification — Flutterwave's own internal numeric
     * transaction_id (returned via the redirect query string / webhook
     * payload), not the tx_ref we generated, is what verify takes.
     *
     * @param array<string, mixed> $config
     * @return array{success: bool, status: string, reference: string, amount: float, metadata: array<string, mixed>}
     */
    public function verifyTransaction(string $transactionId, array $config): array
    {
        $secretKey = (string) ($config['secret_key'] ?? '');
        $response = $this->http->request('GET', self::BASE_URL . '/transactions/' . rawurlencode($transactionId) . '/verify', $this->headers($secretKey));
        $decoded = json_decode($response['body'], true);

        if ($response['status'] !== 200 || !is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            return ['success' => false, 'status' => 'error', 'reference' => $transactionId, 'amount' => 0.0, 'metadata' => []];
        }

        $data = $decoded['data'] ?? [];
        $status = (string) ($data['status'] ?? 'failed');

        return [
            'success' => $status === 'successful',
            'status' => $status,
            'reference' => (string) ($data['tx_ref'] ?? $transactionId),
            'amount' => (float) ($data['amount'] ?? 0),
            'metadata' => (array) ($data['meta'] ?? []),
        ];
    }

    public function refund(array $params): array
    {
        $secretKey = (string) ($params['config']['secret_key'] ?? '');
        $body = json_encode(isset($params['amount']) ? ['amount' => $params['amount']] : []);

        $response = $this->http->request('POST', self::BASE_URL . '/transactions/' . rawurlencode((string) $params['transactionId']) . '/refund', $this->headers($secretKey), $body);
        $decoded = json_decode($response['body'], true);

        return [
            'success' => $response['status'] === 200 && is_array($decoded) && ($decoded['status'] ?? '') === 'success',
            'message' => $decoded['message'] ?? 'Refund request failed.',
        ];
    }

    public function void(array $params): array
    {
        return ['success' => false, 'message' => 'Flutterwave does not support voiding — issue a refund instead.'];
    }

    public function tokenize(array $params): array
    {
        return ['success' => false, 'message' => 'Recurring card tokenization is not yet implemented for Flutterwave.'];
    }

    public function chargeToken(array $params): array
    {
        return ['success' => false, 'message' => 'Recurring card tokenization is not yet implemented for Flutterwave.'];
    }

    /**
     * @param array<string, mixed> $rawPayload
     * @param array<string, string> $headers
     * @return array{valid: bool, event: string, data: array<string, mixed>}
     */
    public function handleCallback(array $rawPayload, array $headers): array
    {
        return [
            'valid' => true,
            'event' => (string) ($rawPayload['event'] ?? $rawPayload['event.type'] ?? 'unknown'),
            'data' => (array) ($rawPayload['data'] ?? []),
        ];
    }

    /** Verifies Flutterwave's verif-hash header — a plain shared-secret string, not an HMAC. */
    public static function verifySignature(string $signatureHeader, string $secretHash): bool
    {
        return $secretHash !== '' && hash_equals($secretHash, $signatureHeader);
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
