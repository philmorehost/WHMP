<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Notifications\NotificationProvider;

/**
 * Third-party/custom outbound notification channels (blueprint §4.5),
 * beyond the two built-in providers (Slack, generic webhook — see
 * core\Notifications\SlackNotificationProvider/WebhookNotificationProvider,
 * which implement NotificationProvider directly and don't need SDK
 * registration). A NotificationModule *is* a NotificationProvider — same
 * send(url, secret, message, context) shape those two already use — plus
 * Module's metadata()/configOptions() so it's admin-discoverable and
 * selectable as an endpoint type the same way "slack"/"webhook" are.
 * NotificationDispatcher resolves any endpoint whose `type` isn't one of
 * the two built-ins by looking up a registered module with that slug.
 */
interface NotificationModule extends Module, NotificationProvider
{
}
