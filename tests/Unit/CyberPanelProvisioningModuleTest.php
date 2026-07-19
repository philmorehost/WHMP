<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Provisioning\CyberPanelProvisioningModule;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class CyberPanelProvisioningModuleTest extends TestCase
{
    private FakeHttpClient $http;
    private CyberPanelProvisioningModule $module;

    /** @var array<string, mixed> */
    private array $server = [
        'hostname' => 'cyberpanel.example.test',
        'api_username' => 'admin',
        'api_token' => 'secret',
        'api_port' => null,
        'use_ssl' => true,
    ];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->module = new CyberPanelProvisioningModule($this->http);
    }

    public function test_create_posts_json_with_admin_credentials_and_correct_endpoint(): void
    {
        $this->http->respondWith(200, json_encode(['status' => 1]));

        $this->module->create(['username' => 'cvuser1', 'server' => $this->server, 'domain' => 'cvuser1.example.com']);

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://cyberpanel.example.test:8090/api/createWebsite', $request['url']);

        $body = json_decode($request['body'], true);
        $this->assertSame('admin', $body['adminUser']);
        $this->assertSame('secret', $body['adminPass']);
        $this->assertSame('cvuser1.example.com', $body['domainName']);
    }

    public function test_create_reports_success_on_status_one(): void
    {
        $this->http->respondWith(200, json_encode(['status' => 1]));

        $result = $this->module->create(['username' => 'cvuser2', 'server' => $this->server]);

        $this->assertTrue($result['success']);
    }

    public function test_create_reports_failure_with_error_message_on_status_zero(): void
    {
        $this->http->respondWith(200, json_encode(['status' => 0, 'error_message' => 'Domain already exists']));

        $result = $this->module->create(['username' => 'cvuser3', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertSame('Domain already exists', $result['message']);
    }

    public function test_suspend_hits_the_expected_endpoint(): void
    {
        $this->http->respondWith(200, json_encode(['status' => 1]));

        $this->module->suspend(['username' => 'cvuser4.example.com', 'server' => $this->server]);

        $request = $this->http->lastRequest();
        $this->assertSame('https://cyberpanel.example.test:8090/api/submitDomainSuspension', $request['url']);
    }

    public function test_single_sign_on_is_explicitly_unsupported(): void
    {
        $result = $this->module->singleSignOn(['username' => 'cvuser5', 'server' => $this->server]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not supported', $result['message']);
    }

    public function test_unreachable_server_reports_failure_without_throwing(): void
    {
        $this->http->respondWith(0, '');

        $result = $this->module->terminate(['username' => 'cvuser6', 'server' => $this->server]);

        $this->assertFalse($result['success']);
    }
}
