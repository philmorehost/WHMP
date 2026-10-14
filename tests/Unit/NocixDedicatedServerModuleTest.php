<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Provisioning\NocixDedicatedServerModule;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Endpoint paths, query variables and response shapes follow Nocix's API reference
 * (https://my.nocix.net/apidoc/, BETA). Requests go to https://my.nocix.net/api/
 * (the old https://manage.nocix.net host does not resolve). A numeric `username`
 * is still taken as the Nocix service id, so these older tests make one call each.
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

        $result = $this->module->usage(['username' => '999', 'server' => $this->server]);

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
        $this->assertSame('https://my.nocix.net/api/list-services-details/', $this->http->lastRequest()['url']);
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
        $this->assertStringContainsString('Could not connect to Nocix', $result['message']);
    }

    public function test_restart_calls_reboot_server(): void
    {
        $this->http->respondWith(200, json_encode(['success' => 'Server rebooted']));

        $result = $this->module->power(['username' => '5001', 'server' => $this->server], 'restart');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('https://my.nocix.net/api/reboot-server/?service_id=5001', $this->http->lastRequest()['url']);
    }

    public function test_start_and_stop_are_refused_without_a_request(): void
    {
        foreach (['start', 'stop'] as $action) {
            $result = $this->module->power(['username' => '5001', 'server' => $this->server], $action);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('only be restarted', $result['message']);
        }

        $this->assertCount(0, $this->http->requests);
    }

    public function test_a_reboot_error_is_reported(): void
    {
        $this->http->respondWith(200, json_encode(['error' => 'Server does not support reboot']));

        $result = $this->module->power(['username' => '5001', 'server' => $this->server], 'restart');

        $this->assertFalse($result['success']);
        $this->assertSame('Server does not support reboot', $result['message']);
    }

    public function test_a_non_json_success_page_is_not_mistaken_for_success(): void
    {
        $this->http->respondWith(200, '<html>Login</html>');

        $result = $this->module->power(['username' => '5001', 'server' => $this->server], 'restart');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('non-JSON', $result['message']);
    }

    public function test_os_list_accepts_the_shapes_nocix_might_return(): void
    {
        $expected = [['file' => 'CentOS 7 64bit', 'name' => 'CentOS 7 64bit'], ['file' => 'Ubuntu 22.04', 'name' => 'Ubuntu 22.04']];

        $this->assertSame($expected, NocixDedicatedServerModule::parseOsList(['CentOS 7 64bit', 'Ubuntu 22.04']));
        $this->assertSame($expected, NocixDedicatedServerModule::parseOsList(['12' => 'CentOS 7 64bit', '15' => 'Ubuntu 22.04']));
        $this->assertSame($expected, NocixDedicatedServerModule::parseOsList([['id' => 1, 'name' => 'CentOS 7 64bit'], ['id' => 2, 'name' => 'Ubuntu 22.04']]));
        $this->assertSame($expected, NocixDedicatedServerModule::parseOsList(['os' => ['CentOS 7 64bit', 'Ubuntu 22.04', 'Ubuntu 22.04']]));
        $this->assertSame([], NocixDedicatedServerModule::parseOsList('nope'));
    }

    public function test_os_templates_calls_os_list(): void
    {
        $this->http->respondWith(200, json_encode(['Debian 12', 'Ubuntu 24.04']));

        $result = $this->module->osTemplates(['username' => '5001', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://my.nocix.net/api/os-list/?service_id=5001', $this->http->lastRequest()['url']);
        $this->assertSame('Debian 12', $result['templates'][0]['file']);
    }

    public function test_os_reload_sends_the_url_encoded_os_name(): void
    {
        $this->http->respondWith(200, json_encode(['success' => 'Reload queued']));

        $result = $this->module->reinstall(['username' => '5001', 'server' => $this->server, 'template' => 'Ubuntu 24.04 (64-bit)']);

        $this->assertTrue($result['success']);
        $this->assertSame('Reload queued', $result['message']);
        $this->assertSame('https://my.nocix.net/api/os-reload/?service_id=5001&os=Ubuntu%2024.04%20%2864-bit%29', $this->http->lastRequest()['url']);
    }

    public function test_os_reload_without_a_template_makes_no_request(): void
    {
        $result = $this->module->reinstall(['username' => '5001', 'server' => $this->server, 'template' => ' ']);

        $this->assertFalse($result['success']);
        $this->assertCount(0, $this->http->requests);
    }

    public function test_reload_status_reads_pending_and_completed(): void
    {
        $this->http->respondWith(200, json_encode(['service' => 5001, 'status' => 'Pending']));
        $result = $this->module->reloadStatus(['username' => '5001', 'server' => $this->server]);
        $this->assertSame('Pending', $result['status']);
        $this->assertSame('https://my.nocix.net/api/reloadstatus/?service=5001', $this->http->lastRequest()['url']);

        $this->http->respondWith(200, json_encode(['service' => 5001, 'status' => 'Completed']));
        $this->assertSame('Completed', $this->module->reloadStatus(['username' => '5001', 'server' => $this->server])['status']);
    }

    public function test_no_reload_yet_is_a_null_status_not_a_failure(): void
    {
        $this->http->respondWith(200, json_encode(['error' => 'No reload found']));

        $result = $this->module->reloadStatus(['username' => '5001', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertNull($result['status']);
    }

    public function test_server_credentials(): void
    {
        $this->http->respondWith(200, json_encode(['service_id' => 5001, 'username' => 'root', 'password' => 's3cret']));

        $result = $this->module->serverCredentials(['username' => '5001', 'server' => $this->server]);

        $this->assertSame(['success' => true, 'message' => '', 'username' => 'root', 'password' => 's3cret'], $result);
        $this->assertSame('https://my.nocix.net/api/get-server-credentials/?service_id=5001', $this->http->lastRequest()['url']);
    }

    public function test_server_credentials_404_is_reported(): void
    {
        $this->http->respondWith(404, json_encode(['error' => 'Service not found or unavailable.']));

        $result = $this->module->serverCredentials(['username' => '5001', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertSame('Service not found or unavailable.', $result['message']);
    }

    public function test_a_linked_remote_id_wins_over_the_username(): void
    {
        $this->http->respondWith(200, json_encode(['success' => 'ok']));

        $this->module->power(['remote_id' => '218686', 'username' => 'client1', 'server' => $this->server], 'restart');

        $this->assertSame('https://my.nocix.net/api/reboot-server/?service_id=218686', $this->http->lastRequest()['url']);
    }

    public function test_an_unlinked_service_is_found_by_an_ip_inside_its_block(): void
    {
        $this->http->respondInSequence([
            ['status' => 200, 'body' => json_encode([
                '111111' => ['name' => 'Other', 'type' => 'Dedicated Server', 'ipaddress' => ['10.0.0.8/29']],
                '218686' => ['name' => 'Dual Xeon', 'type' => 'Dedicated Server', 'ipaddress' => ['69.30.255.184/29', '2604:4300:a::/64']],
            ])],
            ['status' => 200, 'body' => json_encode(['success' => 'ok'])],
        ]);

        $params = ['username' => null, 'dedicated_ip' => '', 'assigned_ips' => "69.30.255.186\n", 'server' => $this->server];
        $result = $this->module->power($params, 'restart');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('https://my.nocix.net/api/list-services-details/', $this->http->requests[0]['url']);
        $this->assertSame('https://my.nocix.net/api/reboot-server/?service_id=218686', $this->http->lastRequest()['url']);
    }

    public function test_an_unmatched_service_is_reported_as_not_linked(): void
    {
        $this->http->respondWith(200, json_encode(['218686' => ['name' => 'x', 'type' => 'Dedicated Server', 'ipaddress' => ['69.30.255.184/29']]]));

        $result = $this->module->power(['dedicated_ip' => '8.8.8.8', 'server' => $this->server], 'restart');

        $this->assertFalse($result['success']);
        $this->assertSame(NocixDedicatedServerModule::NOT_LINKED, $result['message']);
        $this->assertCount(1, $this->http->requests, 'No reboot is sent to a guessed server.');
    }

    public function test_ip_in_block(): void
    {
        $this->assertTrue(NocixDedicatedServerModule::ipInBlock('69.30.255.186', '69.30.255.184/29'));
        $this->assertTrue(NocixDedicatedServerModule::ipInBlock('69.30.255.191', '69.30.255.184/29'));
        $this->assertFalse(NocixDedicatedServerModule::ipInBlock('69.30.255.192', '69.30.255.184/29'));
        $this->assertTrue(NocixDedicatedServerModule::ipInBlock('69.30.255.186', '69.30.255.186'));
        $this->assertTrue(NocixDedicatedServerModule::ipInBlock('2604:4300:a::5', '2604:4300:a::/64'));
        $this->assertFalse(NocixDedicatedServerModule::ipInBlock('69.30.255.186', '2604:4300:a::/64'));
        $this->assertFalse(NocixDedicatedServerModule::ipInBlock('nonsense', '10.0.0.0/8'));
    }

    public function test_remote_services_lists_the_account_for_the_link_picker(): void
    {
        $this->http->respondWith(200, json_encode([
            '218686' => ['name' => 'Dual Xeon 5520 Custom', 'type' => 'Dedicated Server', 'ipaddress' => ['69.30.255.186/29']],
        ]));

        $result = $this->module->remoteServices($this->server);

        $this->assertTrue($result['success']);
        $this->assertSame('218686', $result['services'][0]['ref']);
        $this->assertSame('69.30.255.186', $result['services'][0]['ip']);
        $this->assertSame('#218686 · 69.30.255.186/29 · Dual Xeon 5520 Custom · Dedicated Server', $result['services'][0]['label']);
    }

    public function test_wholesale_accounts_use_their_own_host(): void
    {
        $this->http->respondWith(200, json_encode(['success' => 'ok']));

        $this->module->power(['username' => '5001', 'server' => $this->server + ['hostname' => 'https://my.wholesaleinternet.net']], 'restart');

        $this->assertStringStartsWith('https://my.wholesaleinternet.net/api/reboot-server/', $this->http->lastRequest()['url']);
    }

    public function test_the_old_dead_host_on_a_server_record_is_ignored(): void
    {
        $this->http->respondWith(200, json_encode(['success' => 'ok']));

        $this->module->power(['username' => '5001', 'server' => $this->server + ['hostname' => 'https://manage.nocix.net']], 'restart');

        $this->assertStringStartsWith('https://my.nocix.net/api/', $this->http->lastRequest()['url']);
    }
}
