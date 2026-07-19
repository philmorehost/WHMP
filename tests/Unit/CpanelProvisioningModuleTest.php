<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Provisioning\CpanelProvisioningModule;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class CpanelProvisioningModuleTest extends TestCase
{
    private FakeHttpClient $http;
    private CpanelProvisioningModule $module;

    /** @var array<string, mixed> */
    private array $server = [
        'hostname' => 'whm.example.test',
        'api_username' => 'root',
        'api_token' => 'TOKEN123',
        'api_port' => null,
        'use_ssl' => true,
    ];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->module = new CpanelProvisioningModule($this->http);
    }

    public function test_create_builds_the_correct_whm_api_request(): void
    {
        $this->http->respondWith(200, json_encode(['metadata' => ['result' => 1, 'reason' => 'OK']]));

        $this->module->create(['username' => 'cvuser1', 'server' => $this->server, 'domain' => 'cvuser1.example.com']);

        $request = $this->http->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertStringStartsWith('https://whm.example.test:2087/json-api/createacct?', $request['url']);
        $this->assertStringContainsString('username=cvuser1', $request['url']);
        $this->assertStringContainsString('api.version=1', $request['url']);
        $this->assertSame('whm root:TOKEN123', $request['headers']['Authorization']);
    }

    public function test_create_reports_success_when_whm_result_is_one(): void
    {
        $this->http->respondWith(200, json_encode(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok']]));

        $result = $this->module->create(['username' => 'cvuser2', 'server' => $this->server]);

        $this->assertTrue($result['success']);
    }

    public function test_create_reports_failure_when_whm_result_is_zero(): void
    {
        $this->http->respondWith(200, json_encode(['metadata' => ['result' => 0, 'reason' => 'Username already exists']]));

        $result = $this->module->create(['username' => 'cvuser3', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertSame('Username already exists', $result['message']);
    }

    public function test_unreachable_server_reports_failure_without_throwing(): void
    {
        $this->http->respondWith(0, '');

        $result = $this->module->suspend(['username' => 'cvuser4', 'server' => $this->server]);

        $this->assertFalse($result['success']);
    }

    public function test_suspend_hits_the_suspendacct_endpoint_with_the_username_as_user_param(): void
    {
        $this->http->respondWith(200, json_encode(['metadata' => ['result' => 1]]));

        $this->module->suspend(['username' => 'cvuser5', 'server' => $this->server]);

        $request = $this->http->lastRequest();
        $this->assertStringContainsString('/json-api/suspendacct?', $request['url']);
        $this->assertStringContainsString('user=cvuser5', $request['url']);
    }

    public function test_custom_port_and_non_ssl_are_respected_in_the_url(): void
    {
        $this->http->respondWith(200, json_encode(['metadata' => ['result' => 1]]));
        $server = $this->server;
        $server['api_port'] = 2086;
        $server['use_ssl'] = false;

        $this->module->terminate(['username' => 'cvuser6', 'server' => $server]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('http://whm.example.test:2086/json-api/removeacct?', $request['url']);
    }

    public function test_usage_parses_the_accountsummary_response(): void
    {
        $this->http->respondWith(200, json_encode([
            'metadata' => ['result' => 1],
            'data' => ['acct' => [['diskused' => 100, 'disklimit' => 5000, 'bandwidthused' => 200, 'bandwidthlimit' => 10000]]],
        ]));

        $result = $this->module->usage(['username' => 'cvuser7', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame(100.0, $result['diskUsedMb']);
        $this->assertSame(5000.0, $result['diskLimitMb']);
    }

    /**
     * Regression test: found live against a real WHM server. `version`
     * (and likely other older functions) responds in the legacy
     * `cpanelresult` shape even under /json-api/?api.version=1, not the
     * `metadata` shape account-management functions use. The exact body
     * below is the real response captured from a live server.
     */
    public function test_test_connection_succeeds_on_the_legacy_cpanelresult_success_shape(): void
    {
        $this->http->respondWith(200, '{"cpanelresult":{"apiversion":"2","data":{"version":"11.134.0.44"},"type":"text"}}');

        $result = $this->module->testConnection(['server' => $this->server]);

        $this->assertTrue($result['success']);
    }

    /**
     * Same regression, failure side — this is the real "wrong credentials"
     * response WHM returned live, also in the legacy shape.
     */
    public function test_test_connection_fails_on_the_legacy_cpanelresult_error_shape(): void
    {
        $this->http->respondWith(403, '{"cpanelresult":{"apiversion":"2","error":"Access denied","data":{"reason":"Access denied","result":"0"},"type":"text"}}');

        $result = $this->module->testConnection(['server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertSame('Access denied', $result['message']);
    }

    public function test_usage_parses_the_legacy_cpanelresult_shape_too(): void
    {
        $this->http->respondWith(200, '{"cpanelresult":{"data":{"acct":[{"diskused":50,"disklimit":2000,"bandwidthused":10,"bandwidthlimit":1000}]}}}');

        $result = $this->module->usage(['username' => 'cvuser8', 'server' => $this->server]);

        $this->assertTrue($result['success']);
        $this->assertSame(50.0, $result['diskUsedMb']);
    }

    public function test_usage_reports_account_does_not_exist_after_termination(): void
    {
        // Modeled on the real failure this module reported live for
        // accountsummary against a just-terminated username (the parsed
        // message matched "Account does not exist." — this reconstructs
        // a plausible legacy-shape body producing that same outcome,
        // not a byte-for-byte captured response).
        $this->http->respondWith(200, '{"cpanelresult":{"data":{"reason":"Account does not exist.","result":"0"},"error":"Account does not exist."}}');

        $result = $this->module->usage(['username' => 'cvuser9', 'server' => $this->server]);

        $this->assertFalse($result['success']);
    }
}
