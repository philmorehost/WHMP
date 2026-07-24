<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Modules\GatewayModule;
use CodeVault\Provisioning\HttpClient;

/**
 * Payhub payment gateway integration — the merchant checkout product at
 * merchant.payhub.com.ng (NOT the payhub.com.ng VTU/bill-payment API, which
 * is a different service entirely and has no hosted checkout).
 *
 * Per PayHub's published API reference (merchant.payhub.com.ng/api-reference.php):
 *   POST {BASE_URL}/transaction/initialize   — Bearer secret key, FORM-urlencoded
 *        body of email, amount (kobo), name, phone → returns a hosted checkout URL.
 *   GET  {BASE_URL}/transaction/verify/:reference — Bearer secret key → transaction status.
 *
 * Off-site redirect flow, kobo-based amount, server-side verification before a
 * charge is ever recorded as paid — the same shape as PaystackGateway. Spec-
 * correct per the docs but not live-verified here (no PayHub sandbox keys in
 * this environment); capture()/verifyTransaction() log the raw response on any
 * non-2xx or unexpected shape so a real failure is diagnosable from the error log.
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
     * @param array{config: array<string, mixed>, email: string, name?: string, phone?: string, amount: float, reference: string, callbackUrl: string, metadata?: array<string, mixed>} $params
     * @return array{success: bool, redirectUrl?: string, transactionId?: string, message: string}
     */
    public function capture(array $params): array
    {
        $secretKey = (string) ($params['config']['secret_key'] ?? '');

        if ($secretKey === '') {
            return ['success' => false, 'message' => 'Payhub API is not configured — missing secret key. Please set Payhub Secret Key in Admin Gateway Settings.'];
        }

        // PayHub's documented initialize API (merchant.payhub.com.ng/api-reference.php)
        // takes form-urlencoded fields — its own example is
        //   curl .../api/transaction/initialize -H "Authorization: Bearer KEY"
        //        -d email=... -d amount=... -d name=... -d phone=...
        // so it REQUIRES name and phone alongside email/amount, and expects a
        // form body, not JSON. The previous version sent JSON and omitted
        // name/phone, so initialize rejected every request and the checkout URL
        // never came back — which is exactly why the button just reloaded the
        // invoice. reference/callback_url/metadata are kept as extra fields
        // (PayHub mirrors Paystack's API) so we can return the payer to the
        // right invoice; a gateway that ignores them does no harm.
        $name = trim((string) ($params['name'] ?? ''));
        $fields = [
            'email' => (string) $params['email'],
            // Amount in kobo (smallest unit) — the docs' amount=500000 == ₦5,000.
            'amount' => (int) round($params['amount'] * 100),
            'name' => $name !== '' ? $name : (string) $params['email'],
            'phone' => (string) ($params['phone'] ?? ''),
            'reference' => (string) $params['reference'],
            'callback_url' => (string) $params['callbackUrl'],
            'metadata' => $params['metadata'] ?? [],
        ];

        $response = $this->http->request(
            'POST',
            self::BASE_URL . '/transaction/initialize',
            $this->headers($secretKey),
            http_build_query($fields)
        );

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $data = is_array($decoded) && is_array($decoded['data'] ?? null)
            ? $decoded['data']
            : (is_array($decoded) ? $decoded : []);

        $redirectUrl = (string) (
            $data['authorization_url'] ??
            $data['checkout_url'] ??
            $data['link'] ??
            $data['payment_url'] ??
            $data['url'] ??
            $data['redirect_url'] ??
            ''
        );

        $status = (int) ($response['status'] ?? 0);
        $httpOk = $status >= 200 && $status < 300;

        if (!$httpOk || $redirectUrl === '') {
            // Make the real reason visible instead of a silent "button reloaded":
            // a bad key (401), a validation error (422), or an unexpected
            // response shape all end up here, and the raw body is logged so it
            // can be read in the server error log.
            error_log('Payhub initialize failed: HTTP ' . $status . ' body=' . substr((string) ($response['body'] ?? ''), 0, 600));
            $msg = is_array($decoded)
                ? (string) ($decoded['message'] ?? $decoded['error'] ?? 'Payhub did not return a checkout URL. Check your Payhub Secret Key and that the account is live.')
                : 'Could not reach the Payhub API. Please try again.';
            return ['success' => false, 'message' => $msg];
        }

        return [
            'success' => true,
            'redirectUrl' => $redirectUrl,
            'transactionId' => (string) ($data['reference'] ?? $params['reference']),
            'message' => 'Redirecting to Payhub.',
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
        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        $httpStatus = (int) ($response['status'] ?? 0);
        $httpOk = $httpStatus >= 200 && $httpStatus < 300;

        if (!$httpOk || !is_array($decoded)) {
            error_log('Payhub verify failed: HTTP ' . $httpStatus . ' body=' . substr((string) ($response['body'] ?? ''), 0, 600));
            return ['success' => false, 'status' => 'error', 'reference' => $reference, 'amount' => 0.0, 'metadata' => []];
        }

        // PayHub may wrap the transaction in a "data" object (Paystack-style) or
        // return it flat; the transaction status may be a string ("success") or
        // otherwise — treat any of the recognised success values as paid rather
        // than requiring one exact top-level shape.
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $status = strtolower((string) ($data['status'] ?? 'failed'));

        return [
            'success' => in_array($status, ['success', 'successful', 'completed', 'paid'], true),
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
            // PayHub's documented examples post form-urlencoded bodies, not JSON.
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];
    }
}
