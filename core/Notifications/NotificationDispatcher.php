<?php

declare(strict_types=1);

namespace CodeVault\Notifications;

use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\NotificationModule;
use Throwable;

/**
 * Fans a fired hook point out to every active notification_endpoints row
 * subscribed to it (blueprint §5). Best-effort by design — a Slack
 * outage or a misconfigured webhook must never break the billing/support
 * flow that triggered the notification, so every send is wrapped and
 * failures are swallowed (fail-open, same pattern as the AI features).
 *
 * An endpoint's `type` is either one of the two built-ins ("slack",
 * "webhook") or the slug of a registered NotificationModule (e.g.
 * "discord") — an unrecognized type (module removed/renamed since the
 * endpoint was created) is skipped, not an error, same fail-open posture.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationEndpointRepository $endpoints,
        private readonly SlackNotificationProvider $slack,
        private readonly WebhookNotificationProvider $webhook,
        private readonly ModuleManager $modules
    ) {
    }

    /** @param array<string, mixed> $context */
    public function dispatch(string $hookPoint, string $message, array $context = []): void
    {
        foreach ($this->endpoints->forHookPoint($hookPoint) as $endpoint) {
            $provider = $this->providerFor((string) $endpoint['type']);

            if ($provider === null) {
                continue;
            }

            try {
                $provider->send((string) $endpoint['url'], $endpoint['secret'], $message, $context);
            } catch (Throwable) {
                // Best-effort: a broken endpoint must not affect the caller.
            }
        }
    }

    private function providerFor(string $type): ?NotificationProvider
    {
        if ($type === 'slack') {
            return $this->slack;
        }

        if ($type === 'webhook') {
            return $this->webhook;
        }

        $module = $this->modules->get(NotificationModule::class, $type);

        return $module instanceof NotificationModule ? $module : null;
    }
}
