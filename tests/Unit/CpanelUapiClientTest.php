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
        // A realistic WHM API token. The client treats a secret as a token only
        // when it is 30-64 alphanumeric characters and sends anything else as a
        // password via Basic auth. 'TOKEN123' is EIGHT characters, so it took the
        // password branch -- which is why this test saw Basic auth while asserting
        // the `whm user:token` header.
        'api_token' => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0',
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
        $this->assertSame('whm root:a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0', $request['headers']['Authorization']);
    }

    public function test_a_password_shaped_secret_is_sent_as_basic_auth(): void
    {
        $this->http->respondWith(200, json_encode(['result' => ['status' => 1, 'data' => []]]));

        // The other half of the same rule, and the one the fixture above used to
        // cover by accident. A WHM API token is ~50 alphanumeric characters; a
        // password is not, and Basic auth is what WHM expects for one. Testing
        // only the token branch would leave this rule half-covered.
        $passwordServer = array_merge($this->server, ['api_token' => 'Passw0rd!']);

        $this->client->call($passwordServer, 'cvuser1', 'Email', 'list_pops');

        $this->assertSame(
            'Basic ' . base64_encode('root:Passw0rd!'),
            $this->http->lastRequest()['headers']['Authorization']
        );
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

    // --- the API 2 fallback, and the real server that forced it -----------

    public function test_a_missing_uapi_module_falls_back_to_the_legacy_api_2(): void
    {
        // Verbatim shape of what a real cPanel answered for
        // AddonDomain::addaddondomain: it understood the request and simply does
        // not have the module installed.
        $this->http->respondInSequence([
            $this->json(200, [
                'result' => [
                    'status' => 0,
                    'errors' => ['Failed to load module "AddonDomain": The system failed to load the module '
                        . '"Cpanel::API::AddonDomain" because of an error: Can\'t locate '
                        . 'Cpanel/API/AddonDomain.pm in @INC'],
                    'data' => null,
                ],
            ]),
            $this->json(200, ['cpanelresult' => ['data' => [['domain' => 'shop.example.com']]]]),
        ]);

        $result = $this->client->call($this->server, 'cvuser1', 'AddonDomain', 'listaddondomains');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('2', $result['api_version']);

        $this->assertCount(2, $this->http->requests);
        $this->assertStringContainsString('cpanel_jsonapi_apiversion=3', $this->http->requests[0]['url']);
        $this->assertStringContainsString('cpanel_jsonapi_apiversion=2', $this->http->requests[1]['url']);
    }

    public function test_a_module_error_outside_a_json_envelope_is_still_recognised(): void
    {
        // The same failure, but the panel answered with plain text instead of a
        // JSON envelope. Without quoting it back, the message would read
        // "Unexpected response" and the retry would never happen — the wording
        // IS the signal.
        $this->http->respondInSequence([
            ['status' => 200, 'body' => 'Failed to load module "AddonDomain": Can\'t locate Cpanel/API/AddonDomain.pm'],
            $this->json(200, ['cpanelresult' => ['data' => []]]),
        ]);

        $result = $this->client->call($this->server, 'cvuser1', 'AddonDomain', 'listaddondomains');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('2', $result['api_version']);
        $this->assertCount(2, $this->http->requests);
    }

    public function test_when_both_api_versions_fail_the_message_names_both(): void
    {
        $this->http->respondInSequence([
            $this->json(200, ['result' => ['status' => 0, 'errors' => ['Failed to load module "AddonDomain".'], 'data' => null]]),
            $this->json(200, ['cpanelresult' => ['error' => 'API 2 is not available on this server.']]),
        ]);

        $result = $this->client->call($this->server, 'cvuser1', 'AddonDomain', 'listaddondomains');

        $this->assertFalse($result['success']);
        // The FIRST message is the actionable one (it names the module), so both
        // have to be present rather than only the fallback's.
        $this->assertStringContainsString('AddonDomain', $result['message']);
        $this->assertStringContainsString('Failed to load module', $result['message']);
        $this->assertStringContainsString('API 2 is not available on this server.', $result['message']);
    }

    public function test_an_ordinary_uapi_error_does_not_trigger_the_fallback(): void
    {
        // The fallback must be NARROW. A domain that simply does not exist is a
        // real answer, and re-asking over a different API version would double
        // the calls to say the same thing.
        $this->http->respondWith(200, json_encode([
            'result' => ['status' => 0, 'errors' => ['The addon domain does not exist.'], 'data' => null],
        ]));

        $result = $this->client->call($this->server, 'cvuser1', 'AddonDomain', 'deladdondomain');

        $this->assertFalse($result['success']);
        $this->assertSame('The addon domain does not exist.', $result['message']);
        $this->assertCount(1, $this->http->requests, 'a plain error must not be retried over API 2.');
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

    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): array
    {
        return ['status' => $status, 'body' => (string) json_encode($body)];
    }
}
