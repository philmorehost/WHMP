<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Provisioning\NocixDedicatedServerModule;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Endpoint paths, query variables, and response shapes below are copied
 * from Nocix's own live API reference (my.nocix.net/apidoc, explicitly
 * marked BETA) — not guessed. Unlike InterServerVpsProvisioningModuleTest,
 * every action here makes exactly one HTTP call: Nocix's documented API
 * has no order-placement endpoint, so there is no hostname-to-id
 * resolution step — `username` is treated directly as the real Nocix
 * numeric service id (see the module's class docblock for why).
 */
final class NocixDedicatedServerModuleTest extends TestCase
{
    private FakeHttpClient $http;
    private NocixDedicatedServerModule $module;

    /** @var array<string, mixed> */
    private array $server = [
        'api_username' => 'nocixuser',
        'api_token' => 'TOKEN123',
    ];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->module = new NocixDedicatedServerModule($this->http);
    }

    public function test_create_reports_not_supported_without_making_a_request(): void
    {
        $result = $this->module->create(['username' => '5001', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not support ordering', $result['message']);
        $this->assertCount(0, $this->http->requests);
    }

    public function test_terminate_reports_not_supported_without_making_a_request(): void
    {
        $result = $this->module->terminate(['username' => '5001', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cancellation', $result['message']);
        $this->assertCount(0, $this->http->requests);
    }

    public function test_change_password_reports_not_supported_without_making_a_request(): void
    {
        $result = $this->module->changePassword(['username' => '5001', 'server' => $this->server, 'password' => 'x']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('root password', $result['message']);
        $this->assertCount(0, $this->http->requests);
    }

    public function test_change_package_reports_not_supported_without_making_a_request(): void
    {
        $result = $this->module->changePackage(['username' => '5001', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('plan', $result['message']);
        $this->assertCount(0, $this->http->requests);
    }

    public function test_single_sign_on_reports_not_supported_without_making_a_request(): void
    {
        $result = $this->module->singleSignOn(['username' => '5001', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('console', $result['message']);
        $this->assertCount(0, $this->http->requests);
    }

    public function test_suspend_calls_disconnect_server_with_basic_auth(): void
    {
        $this->http->respondWith(200, json_encode(['status' => 'ok']));

        $result = $this->module->suspend(['username' => '5001', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $request = $this->http->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://my.nocix.net/api/disconnect-server/?service_id=5001', $request['url']);
        $this->assertSame('Basic ' . base64_encode('nocixuser:TOKEN123'), $request['headers']['Authorization']);
    }

    public function test_unsuspend_calls_reconnect_server(): void
    {
        $this->http->respondWith(200, json_encode(['status' => 'ok']));

        $result = $this->module->unsuspend(['username' => '5002', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.nocix.net/api/reconnect-server/?service_id=5002', $this->http->lastRequest()['url']);
    }

    public function test_suspend_reports_the_error_message_from_a_nocix_error_response(): void
    {
        $this->http->respondWith(200, json_encode(['error' => 'Invalid service_id']));

        $result = $this->module->suspend(['username' => '999999', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid service_id', $result['message']);
    }

    public function test_usage_calls_bandwidth_graphing_and_returns_raw_data(): void
    {
        $this->http->respondWith(200, json_encode([
            ['date' => '2026-07-01', 'in' => 1024, 'out' => 2048],
        ]));

        $result = $this->module->usage(['username' => '5003', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.nocix.net/api/bandwidth-graphing/?service_id=5003', $this->http->lastRequest()['url']);
        $this->assertIsArray($result['data']);
    }

    public function test_usage_reports_failure_on_error(): void
    {
        $this->http->respondWith(200, json_encode(['error' => 'Service not found']));

        $result = $this->module->usage(['username' => '0', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertSame('Service not found', $result['message']);
    }

    public function test_test_connection_calls_list_services(): void
    {
        $this->http->respondWith(200, json_encode([
            ['id' => '5001', 'name' => 'srv1.example.com', 'type' => 'dedicated'],
        ]));

        $result = $this->module->testConnection(['server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.nocix.net/api/list-services/', $this->http->lastRequest()['url']);
    }

    public function test_test_connection_fails_on_unauthorized(): void
    {
        $this->http->respondWith(401, json_encode(['error' => 'Invalid credentials']));

        $result = $this->module->testConnection(['server' => $this->server]);

        $this->assertFalse($result['success']);
    }

    public function test_unreachable_api_reports_failure_without_throwing(): void
    {
        $this->http->respondWith(0, '');

        $result = $this->module->testConnection(['server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertSame('Could not reach the Nocix API.', $result['message']);
    }
}
