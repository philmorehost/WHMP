<?php

declare(strict_types=1);

namespace CodeVault\Notifications;

/**
 * Notification providers (blueprint §5) — Slack incoming-webhooks and
 * generic outbound webhooks share this one seam so NotificationDispatcher
 * doesn't need to know which transport a given endpoint uses.
 */
interface NotificationProvider
{
    public function send(string $url, ?string $secret, string $message, array $context): bool;
}
