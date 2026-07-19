<?php

declare(strict_types=1);

namespace CodeVault\Notifications;

use CodeVault\Provisioning\HttpClient;

/**
 * Posts a generic JSON payload to any outbound webhook URL. When the
 * endpoint has a secret configured, the payload is HMAC-SHA256 signed
 * (X-CodeVault-Signature header) so the receiver can verify authenticity —
 * the same pattern Stripe/GitHub webhooks use.
 */
final class WebhookNotificationProvider implements NotificationProvider
{
    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    /** @param array<string, mixed> $context */
    public function send(string $url, ?string $secret, string $message, array $context): bool
    {
        $payload = json_encode([
            'message' => $message,
            'context' => $context,
            'timestamp' => date(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type' => 'application/json'];

        if ($secret !== null && $secret !== '') {
            $headers['X-CodeVault-Signature'] = hash_hmac('sha256', (string) $payload, $secret);
        }

        $result = $this->http->request('POST', $url, $headers, $payload);

        return $result['status'] >= 200 && $result['status'] < 300;
    }
}
