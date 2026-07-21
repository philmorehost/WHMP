<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\CpanelTools\CpanelUapiClient;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class CpanelUapiClientTest extends TestCase
{
    private FakeHttpClient $http;
    private CpanelUapiClient $client;

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
        $this->client = new CpanelUapiClient($this->http);
    }

    public function test_call_builds_the_correct_whm_cpanel_proxy_request(): void
    {
        $this->http->respondWith(200, json_encode(['result' => ['status' => 1, 'data' => []]]));

        $this->client->call($this->server, 'cvuser1', 'Email', 'list_pops');

        $request = $this->http->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertStringStartsWith('https://whm.example.test:2087/json-api/cpanel?', $request['url']);
        $this->assertStringContainsString('cpanel_jsonapi_user=cvuser1', $request['url']);
        $this->assertStringContainsString('cpanel_jsonapi_apiversion=3', $request['url']);
        $this->assertStringContainsString('cpanel_jsonapi_module=Email', $request['url']);
        $this->assertStringContainsString('cpanel_jsonapi_func=list_pops', $request['url']);
        $this->assertSame('whm root:TOKEN123', $request['headers']['Authorization']);
    }

    public function test_call_passes_extra_params_through_the_query_string(): void
    {
        $this->http->respondWith(200, json_encode(['result' => ['status' => 1, 'data' => []]]));

        $this->client->call($this->server, 'cvuser1', 'Email', 'add_pop', ['email' => 'sales', 'domain' => 'example.com']);

        $request = $this->http->lastRequest();
        $this->assertStringContainsString('email=sales', $request['url']);
        $this->assertStringContainsString('domain=example.com', $request['url']);
    }

    public function test_call_reports_success_on_the_documented_result_wrapped_uapi_envelope(): void
    {
        $this->http->respondWith(200, json_encode([
            'result' => ['status' => 1, 'errors' => null, 'data' => [['email' => 'a@example.com']]],
        ]));

        $result = $this->client->call($this->server, 'cvuser1', 'Email', 'list_pops');

        $this->assertTrue($result['success']);
        $this->assertSame([['email' => 'a@example.com']], $result['data']);
    }

    public function test_call_reports_failure_and_joins_errors_when_uapi_status_is_zero(): void
    {
        $this->http->respondWith(200, json_encode([
            'result' => ['status' => 0, 'errors' => ['Domain does not exist.'], 'data' => []],
        ]));

        $result = $this->client->call($this->server, 'cvuser1', 'Email', 'add_pop', ['domain' => 'nope.test']);

        $this->assertFalse($result['success']);
        $this->assertSame('Domain does not exist.', $result['message']);
    }

    public function test_call_also_accepts_an_unwrapped_uapi_envelope(): void
    {
        $this->http->respondWith(200, json_encode(['status' => 1, 'errors' => null, 'data' => ['ok']]));

        $result = $this->client->call($this->server, 'cvuser1', 'Ftp', 'list_ftp');

        $this->assertTrue($result['success']);
        $this->assertSame(['ok'], $result['data']);
    }

    public function test_call_reports_unreachable_server_without_throwing(): void
    {
        $this->http->respondWith(0, '');

        $result = $this->client->call($this->server, 'cvuser1', 'Mysql', 'list_databases');

        $this->assertFalse($result['success']);
        $this->assertSame('Could not reach the WHM server.', $result['message']);
    }

    public function test_call_whm_builds_a_direct_non_proxied_request(): void
    {
        $this->http->respondWith(200, json_encode(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['url' => 'https://whm.example.test/sso']]));

        $result = $this->client->callWhm($this->server, 'create_user_session', ['user' => 'cvuser1', 'service' => 'cpaneld']);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://whm.example.test:2087/json-api/create_user_session?', $request['url']);
        $this->assertStringContainsString('user=cvuser1', $request['url']);
        $this->assertStringContainsString('service=cpaneld', $request['url']);
        $this->assertStringContainsString('api.version=1', $request['url']);
        $this->assertTrue($result['success']);
        $this->assertSame('https://whm.example.test/sso', $result['data']['url']);
    }

    public function test_call_whm_reports_failure_on_metadata_result_zero(): void
    {
        $this->http->respondWith(200, json_encode(['metadata' => ['result' => 0, 'reason' => 'Access denied']]));

        $result = $this->client->callWhm($this->server, 'create_user_session', ['user' => 'cvuser1']);

        $this->assertFalse($result['success']);
        $this->assertSame('Access denied', $result['message']);
    }
}
