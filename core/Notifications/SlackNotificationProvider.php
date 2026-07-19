<?php

declare(strict_types=1);

namespace CodeVault\Notifications;

use CodeVault\Provisioning\HttpClient;

/**
 * Posts to a Slack incoming-webhook URL (the {text: "..."} payload shape
 * every Slack webhook accepts — no bot token or app install needed).
 */
final class SlackNotificationProvider implements NotificationProvider
{
    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    /** @param array<string, mixed> $context */
    public function send(string $url, ?string $secret, string $message, array $context): bool
    {
        $payload = json_encode(['text' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->http->request('POST', $url, ['Content-Type' => 'application/json'], $payload);

        return $result['status'] >= 200 && $result['status'] < 300;
    }
}
