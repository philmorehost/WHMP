<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Modules\GatewayModule;
use CodeVault\Provisioning\HttpClient;

/**
 * Payhub payment gateway integration (merchant.payhub.com.ng).
 * Structured exactly like Paystack (fiat base, kobo-based amount, off-site redirect,
 * server-side verification using standard reference codes).
 */
final class PayhubGateway implements GatewayModule
{
    private const BASE_URL = 'https://merchant.payhub.com.ng/api';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Payhub',
            'description' => 'Card, bank transfer, and USSD payments via Payhub NG.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'secret_key' => ['type' => 'password', 'label' => 'Secret Key', 'default' => ''],
            'public_key' => ['type' => 'text', 'label' => 'Public Key', 'default' => ''],
            'secret_hash' => ['type' => 'password', 'label' => 'Webhook Secret (HMAC)', 'default' => ''],
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
            return ['success' => false, 'message' => 'Payhub API is not configured — missing secret key. Please set Payhub Secret Key in Admin Gateway Settings.'];
        }

        $body = json_encode([
            'email' => $params['email'],
            // Payhub amounts are in the currency's smallest unit (kobo).
            'amount' => (int) round($params['amount'] * 100),
            'reference' => $params['reference'],
            'callback_url' => $params['callbackUrl'],
            'metadata' => $params['metadata'] ?? [],
        ]);

        $response = $this->http->request('POST', self::BASE_URL . '/transaction/initialize', $this->headers($secretKey), $body);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        if ($response['status'] !== 200 || !is_array($decoded) || ($decoded['status'] ?? false) !== true) {
            $msg = is_array($decoded) ? ($decoded['message'] ?? 'Payhub initialization returned an error.') : 'Payhub gateway API connection failed.';
            return ['success' => false, 'message' => $msg];
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $redirectUrl = (string) (
            $data['authorization_url'] ??
            $data['checkout_url'] ??
            $data['link'] ??
            $data['payment_url'] ??
            $data['url'] ??
            $data['redirect_url'] ??
            ''
        );

        return [
            'success' => $redirectUrl !== '',
            'redirectUrl' => $redirectUrl,
            'transactionId' => $data['reference'] ?? $params['reference'],
            'message' => $redirectUrl !== '' ? 'Redirecting to Payhub.' : 'Payhub initialization failed — no redirect URL returned.',
        ];
    }

    /**
     * Server-side verification of a transaction reference.
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
            'success' => $status === 'success' || $status === 'successful',
            'status' => $status,
            'reference' => (string) ($data['reference'] ?? $reference),
            'amount' => ((float) ($data['amount'] ?? 0)) / 100,
            'metadata' => (array) ($data['metadata'] ?? []),
        ];
    }

    public function refund(array $params): array
    {
        return ['success' => false, 'message' => 'Refunds are not currently supported by Payhub module.'];
    }

    public function void(array $params): array
    {
        return ['success' => false, 'message' => 'Voiding is not supported — please refund manually.'];
    }

    public function tokenize(array $params): array
    {
        return ['success' => false, 'message' => 'Tokenization is not supported by Payhub module.'];
    }

    public function chargeToken(array $params): array
    {
        return ['success' => false, 'message' => 'Tokenization is not supported by Payhub module.'];
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
            'event' => (string) ($rawPayload['event'] ?? 'unknown'),
            'data' => (array) ($rawPayload['data'] ?? []),
        ];
    }

    /** Verifies Payhub signature (HMAC-SHA512 of request body) */
    public static function verifySignature(string $rawBody, string $signatureHeader, string $secretKey): bool
    {
        if ($secretKey === '' || $signatureHeader === '') {
            return false;
        }
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
