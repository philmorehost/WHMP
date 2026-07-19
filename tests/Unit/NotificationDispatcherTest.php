<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\NotificationModule;
use CodeVault\Notifications\DiscordNotificationModule;
use CodeVault\Notifications\NotificationDispatcher;
use CodeVault\Notifications\NotificationEndpointRepository;
use CodeVault\Notifications\SlackNotificationProvider;
use CodeVault\Notifications\WebhookNotificationProvider;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;

final class NotificationDispatcherTest extends DatabaseTestCase
{
    private NotificationEndpointRepository $endpoints;
    private FakeHttpClient $http;
    private ModuleManager $modules;
    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->endpoints = new NotificationEndpointRepository($this->db);
        $this->http = new FakeHttpClient();
        $this->modules = new ModuleManager(new HookDispatcher());
        $this->modules->register(NotificationModule::class, 'discord', new DiscordNotificationModule($this->http));

        $this->dispatcher = new NotificationDispatcher(
            $this->endpoints,
            new SlackNotificationProvider($this->http),
            new WebhookNotificationProvider($this->http),
            $this->modules
        );
    }

    public function test_dispatch_sends_to_a_subscribed_active_slack_endpoint(): void
    {
        $this->endpoints->create('slack', 'Billing Alerts', 'https://hooks.slack.com/services/T/X', null, ['OrderPlaced']);

        $this->dispatcher->dispatch('OrderPlaced', 'New order #1 placed — $10.00');

        $this->assertCount(1, $this->http->requests);
        $this->assertSame('https://hooks.slack.com/services/T/X', $this->http->lastRequest()['url']);
        $body = json_decode((string) $this->http->lastRequest()['body'], true);
        $this->assertSame('New order #1 placed — $10.00', $body['text']);
    }

    public function test_dispatch_skips_endpoints_not_subscribed_to_this_event(): void
    {
        $this->endpoints->create('slack', 'Tickets Only', 'https://hooks.slack.com/services/T/X', null, ['TicketOpen']);

        $this->dispatcher->dispatch('OrderPlaced', 'New order #1 placed');

        $this->assertCount(0, $this->http->requests);
    }

    public function test_dispatch_skips_inactive_endpoints(): void
    {
        $id = $this->endpoints->create('slack', 'Disabled', 'https://hooks.slack.com/services/T/X', null, ['OrderPlaced']);
        $this->endpoints->setActive($id, false);

        $this->dispatcher->dispatch('OrderPlaced', 'New order #1 placed');

        $this->assertCount(0, $this->http->requests);
    }

    public function test_dispatch_sends_to_multiple_subscribed_endpoints(): void
    {
        $this->endpoints->create('slack', 'Channel A', 'https://hooks.slack.com/a', null, ['OrderPlaced']);
        $this->endpoints->create('webhook', 'External System', 'https://example.test/hook', 'sekret', ['OrderPlaced']);

        $this->dispatcher->dispatch('OrderPlaced', 'New order #1 placed');

        $this->assertCount(2, $this->http->requests);
    }

    public function test_webhook_provider_signs_the_payload_when_a_secret_is_configured(): void
    {
        $this->endpoints->create('webhook', 'Signed', 'https://example.test/hook', 'my-secret', ['OrderPlaced']);

        $this->dispatcher->dispatch('OrderPlaced', 'New order #1 placed', ['orderId' => 1]);

        $sent = $this->http->lastRequest();
        $this->assertArrayHasKey('X-CodeVault-Signature', $sent['headers']);

        $expected = hash_hmac('sha256', (string) $sent['body'], 'my-secret');
        $this->assertSame($expected, $sent['headers']['X-CodeVault-Signature']);
    }

    public function test_webhook_provider_omits_signature_header_without_a_secret(): void
    {
        $this->endpoints->create('webhook', 'Unsigned', 'https://example.test/hook', null, ['OrderPlaced']);

        $this->dispatcher->dispatch('OrderPlaced', 'New order #1 placed');

        $this->assertArrayNotHasKey('X-CodeVault-Signature', $this->http->lastRequest()['headers']);
    }

    // --- R24: NotificationModule resolution (endpoint type = registered module slug) ----

    public function test_dispatch_resolves_a_registered_module_type_by_slug(): void
    {
        $this->endpoints->create('discord', 'Team Channel', 'https://discord.com/api/webhooks/1/abc', null, ['OrderPlaced']);

        $this->dispatcher->dispatch('OrderPlaced', 'New order #1 placed — $10.00');

        $this->assertCount(1, $this->http->requests);
        $this->assertSame('https://discord.com/api/webhooks/1/abc', $this->http->lastRequest()['url']);
        $body = json_decode((string) $this->http->lastRequest()['body'], true);
        $this->assertSame('New order #1 placed — $10.00', $body['content']);
    }

    public function test_dispatch_skips_an_endpoint_whose_module_type_is_not_registered(): void
    {
        $this->endpoints->create('some-removed-module', 'Stale', 'https://example.test/gone', null, ['OrderPlaced']);

        $this->dispatcher->dispatch('OrderPlaced', 'New order #1 placed');

        $this->assertCount(0, $this->http->requests);
    }

    // --- R24: DiscordNotificationModule direct unit coverage ---------------

    public function test_discord_module_exposes_sdk_metadata(): void
    {
        $module = new DiscordNotificationModule($this->http);

        $metadata = $module->metadata();

        $this->assertSame('Discord', $metadata['name']);
        $this->assertSame([], $module->configOptions());
    }

    public function test_discord_module_send_returns_true_on_a_2xx_response(): void
    {
        $this->http->respondWith(204, '');
        $module = new DiscordNotificationModule($this->http);

        $this->assertTrue($module->send('https://discord.com/api/webhooks/1/abc', null, 'Hello', []));
    }

    public function test_discord_module_send_returns_false_on_a_non_2xx_response(): void
    {
        $this->http->respondWith(404, 'Unknown Webhook');
        $module = new DiscordNotificationModule($this->http);

        $this->assertFalse($module->send('https://discord.com/api/webhooks/1/abc', null, 'Hello', []));
    }

    public function test_dispatch_never_throws_even_when_the_provider_errors(): void
    {
        $this->endpoints->create('slack', 'Down', 'https://hooks.slack.com/services/T/X', null, ['OrderPlaced']);
        $this->http->respondWith(0, '');

        $this->dispatcher->dispatch('OrderPlaced', 'New order #1 placed');

        $this->assertCount(1, $this->http->requests, 'the send was still attempted');
    }
}
