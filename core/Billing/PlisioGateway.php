<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Modules\GatewayModule;
use CodeVault\Provisioning\HttpClient;

/**
 * Plisio cryptocurrency payment gateway integration (plisio.net).
 * Redirect-based: capture() initializes an invoice and redirects to Plisio's hosted page;
 * verifyTransaction() searches operations by order reference to confirm payment.
 */
final class PlisioGateway implements GatewayModule
{
    private const BASE_URL = 'https://api.plisio.net/api/v1';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Plisio Crypto',
            'description' => 'Cryptocurrency payments (BTC, ETH, LTC, etc.) via Plisio.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'api_key' => ['type' => 'password', 'label' => 'API Secret Key', 'default' => ''],
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
        $apiKey = (string) ($params['config']['api_key'] ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'message' => 'Plisio API is not configured — missing API key. Please configure Plisio Secret / API Key in Admin -> Gateways.'];
        }

        $invoiceId = (int) ($params['metadata']['invoice_id'] ?? 0);

        $queryParams = [
            'api_key' => $apiKey,
            'order_number' => $params['reference'],
            'order_name' => "Invoice #{$invoiceId}",
            'source_amount' => number_format($params['amount'], 2, '.', ''),
            'source_currency' => $params['currency'] ?? 'USD',
            'callback_url' => str_replace('/callback', '/webhook', $params['callbackUrl']) . '?json=true',
            'success_url' => $params['callbackUrl'] . '?reference=' . urlencode($params['reference']),
            'cancel_url' => $params['callbackUrl'] . '?reference=' . urlencode($params['reference']),
            'email' => $params['email'],
            'json' => 'true',
        ];

        $url = self::BASE_URL . '/invoices/new?' . http_build_query($queryParams);
        $response = $this->http->request('GET', $url);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        if ($response['status'] !== 200 || !is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            $msg = is_array($decoded) ? ($decoded['data']['message'] ?? $decoded['message'] ?? 'Plisio initialization returned an error.') : 'Plisio API connection failed.';
            return ['success' => false, 'message' => $msg];
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $redirectUrl = (string) ($data['invoice_url'] ?? $data['url'] ?? '');

        return [
            'success' => $redirectUrl !== '',
            'redirectUrl' => $redirectUrl,
            'transactionId' => $data['txn_id'] ?? $params['reference'],
            'message' => $redirectUrl !== '' ? 'Redirecting to Plisio.' : 'Plisio initialization failed — no redirect URL returned.',
        ];
    }

    /**
     * Server-side verification of transaction status.
     *
     * @param array<string, mixed> $config
     * @return array{success: bool, status: string, reference: string, amount: float, metadata: array<string, mixed>}
     */
    public function verifyTransaction(string $reference, array $config): array
    {
        $apiKey = (string) ($config['api_key'] ?? '');
        
        $url = self::BASE_URL . '/operations?' . http_build_query([
            'api_key' => $apiKey,
            'search' => $reference,
        ]);
        $response = $this->http->request('GET', $url);
        $decoded = json_decode($response['body'], true);

        if ($response['status'] !== 200 || !is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            return ['success' => false, 'status' => 'error', 'reference' => $reference, 'amount' => 0.0, 'metadata' => []];
        }

        $operations = $decoded['data']['operations'] ?? [];
        if (empty($operations)) {
            return ['success' => false, 'status' => 'not_found', 'reference' => $reference, 'amount' => 0.0, 'metadata' => []];
        }

        $first = $operations[0];
        $status = (string) ($first['status'] ?? 'failed');
        $success = ($status === 'completed' || $status === 'mismatch');

        return [
            'success' => $success,
            'status' => $status,
            'reference' => $reference,
            'amount' => (float) ($first['source_amount'] ?? $first['amount'] ?? 0.0),
            'metadata' => ['invoice_id' => $this->parseInvoiceId($reference)],
        ];
    }

    public function refund(array $params): array
    {
        return ['success' => false, 'message' => 'Refunds are not supported by the Plisio cryptocurrency module.'];
    }

    public function void(array $params): array
    {
        return ['success' => false, 'message' => 'Voiding is not supported — please refund manually.'];
    }

    public function tokenize(array $params): array
    {
        return ['success' => false, 'message' => 'Tokenization is not supported by Plisio module.'];
    }

    public function chargeToken(array $params): array
    {
        return ['success' => false, 'message' => 'Tokenization is not supported by Plisio module.'];
    }

    /**
     * @param array<string, mixed> $rawPayload
     * @param array<string, string> $headers
     * @return array{valid: bool, event: string, data: array<string, mixed>}
     */
    public function handleCallback(array $rawPayload, array $headers): array
    {
        // Plisio webhooks return operation details directly in the payload
        return [
            'valid' => isset($rawPayload['status']) && $rawPayload['status'] === 'success',
            'event' => (string) ($rawPayload['event'] ?? 'status_change'),
            'data' => [
                'reference' => (string) ($rawPayload['order_number'] ?? ''),
                'tx_ref' => (string) ($rawPayload['order_number'] ?? ''),
                'status' => (string) ($rawPayload['status'] ?? ''),
            ],
        ];
    }

    /** Verifies Plisio webhook signature using callback secret */
    public static function verifySignature(array $postParams, string $secretKey): bool
    {
        if (!isset($postParams['verify_hash'])) {
            return false;
        }
        
        $verifyHash = $postParams['verify_hash'];
        unset($postParams['verify_hash']);
        ksort($postParams);
        
        $checkString = http_build_query($postParams) . $secretKey;
        
        return hash_equals(sha1($checkString), $verifyHash);
    }

    private function parseInvoiceId(string $reference): ?int
    {
        if (preg_match('/^cv-plisio-(\d+)-/', $reference, $matches) === 1) {
            return (int) $matches[1];
        }
        return null;
    }
}
