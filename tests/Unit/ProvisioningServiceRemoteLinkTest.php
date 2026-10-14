<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Provisioning\InterServerVpsProvisioningModule;
use CodeVault\Provisioning\LinksRemoteServices;
use CodeVault\Provisioning\NocixDedicatedServerModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Security\SecretBox;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Fixtures\FakeProvisioningModule;
use CodeVault\Tests\Support\ScriptedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * A VPS bought and set up by hand on InterServer has no WHMP username. These pin
 * that such a service still reaches the module (by its link, hostname or IP), that
 * the encrypted account password reaches the module decrypted and the ciphertext
 * never does, and that other modules still require a username.
 */
final class ProvisioningServiceRemoteLinkTest extends TestCase
{
    private const UUID = '0b7e2c1a-1111-4222-8333-444455556666';

    private FakeHttpClient $http;

    private SecretBox $box;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient(200, '{"text":"queued","queueId":1}');
        $this->box = new SecretBox(null, str_repeat('k', 32));
    }

    /** @param array<string, mixed> $service @param array<string, mixed> $server */
    private function service(array $service, array $server, ?ProvisioningModule $module = null, string $slug = 'interserver-vps'): ProvisioningService
    {
        $db = new ScriptedDatabase();
        $db->on('/FROM services s/', [$service + [
            'id' => 7, 'client_id' => 1, 'server_id' => 3, 'username' => null, 'remote_id' => null,
            'domain' => null, 'hostname' => null, 'password' => null, 'product_name' => 'VPS 2',
            'dedicated_ip' => null, 'assigned_ips' => null, 'status' => 'active',
        ]]);
        $db->on('/FROM servers WHERE id/', [$server + ['id' => 3, 'name' => 'IS', 'module_slug' => $slug, 'api_username' => 'u', 'api_token' => 'KEY', 'account_secret' => null]]);

        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $modules->register(ProvisioningModule::class, $slug, $module ?? ($slug === 'nocix-dedicated' ? new NocixDedicatedServerModule($this->http) : new InterServerVpsProvisioningModule($this->http)));

        return new ProvisioningService(
            new ServiceRepository($db),
            new ProductRepository($db),
            new ServerRepository($db),
            $modules,
            $hooks,
            $this->box
        );
    }

    public function test_a_linked_service_without_a_username_is_controllable(): void
    {
        $svc = $this->service(['remote_id' => self::UUID], []);

        $result = $svc->power(7, 'restart');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringContainsString('/vps/' . self::UUID . '/restart', $this->http->lastRequest()['url']);
        $this->assertCount(1, $this->http->requests, 'A linked id needs no GET /vps lookup.');
    }

    public function test_an_unlinked_service_is_matched_by_its_ip(): void
    {
        $this->http->respondInSequence([
            ['status' => 200, 'body' => json_encode([
                ['vps_id' => 5, 'vps_uuid' => 'aaaa-other', 'vps_hostname' => 'other.example', 'vps_ip' => '10.0.0.9'],
                ['vps_id' => 6, 'vps_uuid' => self::UUID, 'vps_hostname' => 'box.isp.example', 'vps_ip' => '203.0.113.20'],
            ])],
        ]);
        $svc = $this->service(['hostname' => 'client-chosen.example', 'dedicated_ip' => '203.0.113.20'], []);

        $link = $svc->remoteLink(7);

        $this->assertSame(['ref' => self::UUID, 'via' => 'ip', 'linked' => null], $link);
    }

    public function test_the_account_password_reaches_the_module_decrypted_and_never_as_ciphertext(): void
    {
        $recorder = new class implements ProvisioningModule, LinksRemoteServices {
            /** @var array<string, mixed>|null */
            public ?array $lastParams = null;
            public function metadata(): array { return ['name' => 'rec']; }
            public function configOptions(): array { return []; }
            public function create(array $params): array { return ['success' => true, 'message' => '']; }
            public function suspend(array $params): array { return ['success' => true, 'message' => '']; }
            public function unsuspend(array $params): array { return ['success' => true, 'message' => '']; }
            public function terminate(array $params): array { return ['success' => true, 'message' => '']; }
            public function changePassword(array $params): array { return ['success' => true, 'message' => '']; }
            public function changePackage(array $params): array { return ['success' => true, 'message' => '']; }
            public function singleSignOn(array $params): array { return ['success' => true, 'message' => '', 'url' => null]; }
            public function usage(array $params): array { return []; }
            public function testConnection(array $params): array { return ['success' => true, 'message' => '']; }
            public function remoteServices(array $server): array { return ['success' => true, 'message' => '', 'services' => []]; }
            public function resolveRemote(array $params): array { return ['ref' => null, 'via' => 'none']; }
            public function reinstall(array $params): array { $this->lastParams = $params; return ['success' => true, 'message' => 'ok']; }
        };
        $svc = $this->service([], ['account_secret' => $this->box->encrypt('MyAdmin-Pa55')], $recorder);

        $svc->reinstall(7, 'ubuntu-22.04', '', 'NewRoot123');

        $params = $recorder->lastParams;
        $this->assertIsArray($params, 'A username-less service on a linking module reaches the module.');
        $this->assertSame('MyAdmin-Pa55', $params['server']['account_password'] ?? null);
        $this->assertArrayNotHasKey('account_secret', $params['server']);
        $this->assertSame('NewRoot123', $params['password']);
    }

    public function test_reinstall_sends_the_saved_account_password_and_the_new_root_password(): void
    {
        $svc = $this->service(['remote_id' => self::UUID], ['account_secret' => $this->box->encrypt('MyAdmin-Pa55')]);

        $result = $svc->reinstall(7, 'ubuntu-22.04', '', 'NewRoot123');

        $this->assertTrue($result['success'], $result['message']);
        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertStringContainsString('/vps/' . self::UUID . '/reinstall_os', $request['url']);
        $body = json_decode((string) $request['body'], true);
        $this->assertSame(['template' => 'ubuntu-22.04', 'localPassword' => 'MyAdmin-Pa55', 'password' => 'NewRoot123'], $body);
    }

    public function test_reinstall_without_a_saved_account_password_fails_without_calling_the_api(): void
    {
        $svc = $this->service(['remote_id' => self::UUID], []);

        $result = $svc->reinstall(7, 'ubuntu-22.04', '', 'NewRoot123');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('account password', $result['message']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_a_foreign_or_corrupt_secret_is_ignored_rather_than_passed_on(): void
    {
        $svc = $this->service(['remote_id' => self::UUID], ['account_secret' => 'sb1:not-really']);

        $result = $svc->restore(7, 'swift:6:snap-1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('account password', $result['message']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_restore_posts_the_backup_and_account_password(): void
    {
        $svc = $this->service(['remote_id' => self::UUID], ['account_secret' => $this->box->encrypt('MyAdmin-Pa55')]);

        $svc->restore(7, 'swift:6:snap-1');

        $request = $this->http->lastRequest();
        $this->assertStringContainsString('/vps/' . self::UUID . '/restore', $request['url']);
        $this->assertSame(['backup' => 'swift:6:snap-1', 'password' => 'MyAdmin-Pa55'], json_decode((string) $request['body'], true));
    }

    public function test_other_modules_still_need_a_username(): void
    {
        $fake = new FakeProvisioningModule();
        $svc = $this->service([], [], $fake);

        $result = $svc->power(7, 'start');

        $this->assertFalse($result['success']);
        $this->assertSame('Service has not been provisioned yet.', $result['message']);
    }

    public function test_server_link_capability_and_listing(): void
    {
        $this->http->respondWith(200, json_encode([
            ['vps_id' => 6, 'vps_uuid' => self::UUID, 'vps_hostname' => 'box.isp.example', 'vps_ip' => '203.0.113.20', 'vps_status' => 'active'],
        ]));
        $svc = $this->service([], []);

        $this->assertTrue($svc->serverLinksRemoteServices(3));
        $this->assertFalse($svc->serverLinksRemoteServices(null));
        $listing = $svc->remoteServicesFor(3);
        $this->assertTrue($listing['success']);
        $this->assertSame(self::UUID, $listing['services'][0]['ref']);
    }

    public function test_a_hand_set_up_nocix_server_without_a_username_reloads_by_its_link(): void
    {
        $this->http->respondWith(200, json_encode(['success' => 'Reload queued']));
        $svc = $this->service(['remote_id' => '218686'], [], null, 'nocix-dedicated');

        $result = $svc->reinstall(7, 'Debian 12');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('https://my.nocix.net/api/os-reload/?service_id=218686&os=Debian%2012', $this->http->lastRequest()['url']);
    }

    public function test_nocix_reload_status_and_login_route_to_the_module(): void
    {
        $svc = $this->service(['remote_id' => '218686'], [], null, 'nocix-dedicated');

        $this->http->respondWith(200, json_encode(['service' => 218686, 'status' => 'Completed']));
        $this->assertSame('Completed', $svc->reloadStatus(7)['status']);

        $this->http->respondWith(200, json_encode(['service_id' => 218686, 'username' => 'root', 'password' => 'pw']));
        $this->assertSame('pw', $svc->serverCredentials(7)['password']);
    }

    public function test_reload_status_on_a_module_without_it_says_so(): void
    {
        $svc = $this->service(['remote_id' => self::UUID], []);

        $result = $svc->reloadStatus(7);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not report OS reload progress', $result['message']);
    }
}
