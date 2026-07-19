<?php

declare(strict_types=1);

namespace CodeVault\Notifications;

use CodeVault\Modules\NotificationModule;
use CodeVault\Provisioning\HttpClient;

/**
 * The reference NotificationModule implementation (R24) — proves the SDK
 * end-to-end the same way SystemDiagnosticsAddon (R20) and TopClientsWidget
 * (R21) did for their SDKs: a real, useful channel rather than a
 * placeholder. Posts to a Discord incoming-webhook URL (the
 * {"content": "..."} payload shape every Discord webhook accepts — no bot
 * token or app install needed, same posture as SlackNotificationProvider).
 * Discord webhooks have no signing mechanism, so $secret is accepted (to
 * satisfy the shared NotificationProvider contract) but unused, same as
 * SlackNotificationProvider.
 */
final class DiscordNotificationModule implements NotificationModule
{
    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Discord',
            'description' => 'Posts admin event notifications to a Discord channel via an incoming webhook URL.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    /** @param array<string, mixed> $context */
    public function send(string $url, ?string $secret, string $message, array $context): bool
    {
        $payload = json_encode(['content' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->http->request('POST', $url, ['Content-Type' => 'application/json'], $payload);

        return $result['status'] >= 200 && $result['status'] < 300;
    }
}
