<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Domains\UpperlinkRegistrarModule;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class UpperlinkRegistrarModuleTest extends TestCase
{
    private FakeHttpClient $http;
    private UpperlinkRegistrarModule $module;

    /** @var array<string, mixed> */
    private array $registrar = [
        'email' => 'reseller@example.test',
        'api_key' => '1234567890QWERTYUIOPASDFGHJKLZXCVBNM',
    ];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->module = new UpperlinkRegistrarModule($this->http);
    }

    /**
     * The exact algorithm from the provided spec:
     * base64_encode(hash_hmac("sha256", "<api-key>", "<email>:<gmdate('y-m-d H')>"))
     */
    private function expectedToken(): string
    {
        $hourKey = gmdate('y-m-d H');

        return base64_encode(hash_hmac('sha256', $this->registrar['api_key'], "{$this->registrar['email']}:{$hourKey}"));
    }

    public function test_auth_headers_match_the_documented_hmac_algorithm_exactly(): void
    {
        $this->http->respondWith(200, '{"status":"success"}');

        $this->module->renew(['domain' => 'example.com', 'years' => 1, 'registrar' => $this->registrar]);

        $request = $this->http->lastRequest();
        $this->assertSame($this->registrar['email'], $request['headers']['username']);
        $this->assertSame($this->expectedToken(), $request['headers']['token']);
    }

    public function test_register_posts_to_the_documented_endpoint_with_form_encoded_body(): void
    {
        $this->http->respondWith(200, '{"status":"success"}');

        $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 2,
            'registrar' => $this->registrar,
        ]);

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://client.upperlink.ng/clients/modules/addons/DomainsReseller/api/index.php/order/domains/register', $request['url']);
        $this->assertStringContainsString('domain=newdomain.com', $request['body']);
        $this->assertStringContainsString('regperiod=2', $request['body']);
    }

    public function test_renew_hits_the_renew_endpoint(): void
    {
        $this->http->respondWith(200, '{"status":"success"}');

        $this->module->renew(['domain' => 'renewthis.com', 'years' => 1, 'registrar' => $this->registrar]);

        $this->assertStringEndsWith('/order/domains/renew', $this->http->lastRequest()['url']);
    }

    public function test_get_nameservers_is_a_get_request_with_domain_in_the_path(): void
    {
        $this->http->respondWith(200, '{"status":"success","data":{"nameservers":["ns1.test","ns2.test"]}}');

        $result = $this->module->getNameservers(['domain' => 'nsdomain.com', 'registrar' => $this->registrar]);

        $request = $this->http->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertStringEndsWith('/domains/nsdomain.com/nameservers', $request['url']);
        $this->assertTrue($result['success']);
        $this->assertSame(['ns1.test', 'ns2.test'], $result['nameservers']);
    }

    public function test_set_registrar_lock_sends_lockstatus_param(): void
    {
        $this->http->respondWith(200, '{"status":"success"}');

        $this->module->setRegistrarLock(['domain' => 'lockdomain.com', 'lock' => true, 'registrar' => $this->registrar]);

        $request = $this->http->lastRequest();
        $this->assertStringContainsString('lockstatus=1', $request['body']);
    }

    public function test_protect_id_sends_status_flag(): void
    {
        $this->http->respondWith(200, '{"status":"success"}');

        $this->module->enableIdProtection(['domain' => 'protectdomain.com', 'registrar' => $this->registrar]);

        $request = $this->http->lastRequest();
        $this->assertStringEndsWith('/domains/protectdomain.com/protectid', $request['url']);
        $this->assertStringContainsString('status=1', $request['body']);
    }

    public function test_availability_check_posts_to_domains_lookup(): void
    {
        $this->http->respondWith(200, '{"status":"success","data":{"available":true}}');

        $result = $this->module->checkAvailability(['domain' => 'checkme.com', 'registrar' => $this->registrar]);

        $this->assertStringEndsWith('/domains/lookup', $this->http->lastRequest()['url']);
        $this->assertTrue($result['available']);
    }

    public function test_explicit_error_status_is_reported_as_failure(): void
    {
        $this->http->respondWith(200, '{"status":"error","message":"Domain not found"}');

        $result = $this->module->renew(['domain' => 'nope.com', 'years' => 1, 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertSame('Domain not found', $result['message']);
    }

    public function test_unreachable_api_reports_failure_without_throwing(): void
    {
        $this->http->respondWith(0, '');

        $result = $this->module->renew(['domain' => 'unreachable.com', 'years' => 1, 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
    }

    /**
     * Confidence-hardening test (WHMP task: harden Upperlink against
     * plausible-but-undocumented response shapes). A very common REST
     * convention is an explicit boolean `success` field alongside HTTP 200 —
     * decode()'s heuristic (no `error` key + `status`/`result` not one of
     * error/fail/failed => success) would otherwise misclassify this as a
     * success even though the API explicitly said it failed.
     */
    public function test_explicit_success_false_field_is_reported_as_failure(): void
    {
        $this->http->respondWith(200, '{"success":false,"message":"Domain does not exist"}');

        $result = $this->module->renew(['domain' => 'nope2.com', 'years' => 1, 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertSame('Domain does not exist', $result['message']);
    }

    /** Same convention, inverse case — should still resolve to success. */
    public function test_explicit_success_true_field_is_reported_as_success(): void
    {
        $this->http->respondWith(200, '{"success":true,"data":{"nameservers":["ns1.test","ns2.test"]}}');

        $result = $this->module->getNameservers(['domain' => 'nsdomain2.com', 'registrar' => $this->registrar]);

        $this->assertTrue($result['success']);
        $this->assertSame(['ns1.test', 'ns2.test'], $result['nameservers']);
    }

    /** `status` as an integer code (e.g. 1 = OK) rather than a string keyword. */
    public function test_integer_status_code_does_not_get_misread_as_an_error_keyword(): void
    {
        $this->http->respondWith(200, '{"status":1,"message":"OK"}');

        $result = $this->module->renew(['domain' => 'intstatus.com', 'years' => 1, 'registrar' => $this->registrar]);

        $this->assertTrue($result['success']);
    }

    /** Flat (non-nested) data shape — no wrapping `data` key at all. */
    public function test_flat_response_without_a_data_wrapper_is_still_read_correctly(): void
    {
        $this->http->respondWith(200, '{"status":"success","lockstatus":true}');

        $result = $this->module->getRegistrarLock(['domain' => 'flatlock.com', 'registrar' => $this->registrar]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['locked']);
    }

    /**
     * Regression coverage: sync() called $this->mapStatus() but that method
     * didn't exist anywhere in the class — any domain with a non-empty
     * status field threw a fatal Error the moment sync() ran. Caught by
     * CronScheduler's per-job Throwable handler, so it didn't crash other
     * cron jobs, but it did abort the whole DomainSyncJob run as soon as it
     * reached the first such domain — silently skipping every other domain
     * (any registrar) still queued behind it that day.
     */
    public function test_sync_no_longer_throws_on_a_domain_with_a_non_empty_status(): void
    {
        $this->http->respondWith(200, '{"status":"success","data":{"status":"active","expiryDate":"2028-01-01"}}');

        $result = $this->module->sync(['domain' => 'example.com', 'registrar' => $this->registrar]);

        $this->assertTrue($result['success']);
        $this->assertSame('active', $result['status']);
    }

    public function test_sync_never_guesses_a_status_for_an_unrecognised_registry_value(): void
    {
        $this->http->respondWith(200, '{"status":"success","data":{"status":"SomeStatusUpperlinkAddsLater","expiryDate":"2028-01-01"}}');

        $result = $this->module->sync(['domain' => 'example.com', 'registrar' => $this->registrar]);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('status', $result, 'an unrecognised status must never fall back to a guessed value');
    }

    public function test_sync_never_guesses_a_status_when_the_status_field_is_missing(): void
    {
        $this->http->respondWith(200, '{"status":"success","data":{"expiryDate":"2028-01-01"}}');

        $result = $this->module->sync(['domain' => 'example.com', 'registrar' => $this->registrar]);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('status', $result, 'a missing status field must not default to "active"');
    }
}
