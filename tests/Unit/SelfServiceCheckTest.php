<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Provisioning\HttpClient;
use CodeVault\Provisioning\InterServerVpsProvisioningModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Security\SecretBox;
use CodeVault\Tests\Support\ScriptedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The admin's "self-service check" says exactly why a client's server button would
 * open a ticket, and never calls anything that changes the machine. Also pins that
 * a refusal InterServer explains itself (409 "VPS is not active") is flagged so the
 * client is told why instead of a ticket being opened, while reinstall/restore and
 * firewall blocks are not.
 */
final class SelfServiceCheckTest extends TestCase
{
    private const UUID = '0b7e2c1a-1111-4222-8333-444455556666';

    /** @var object{routes: array<string, array{status: int, body: string}>, requests: array<int, array<string, mixed>>}&HttpClient */
    private object $http;

    private SecretBox $box;

    protected function setUp(): void
    {
        $this->box = new SecretBox(null, str_repeat('k', 32));
        $this->http = new class () implements HttpClient {
            /** @var array<string, array{status: int, body: string}> path suffix => response */
            public array $routes = [];

            /** @var array<int, array<string, mixed>> */
            public array $requests = [];

            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                $this->requests[] = compact('method', 'url', 'body');
                $path = (string) parse_url($url, PHP_URL_PATH);

                foreach ($this->routes as $suffix => $response) {
                    if (str_ends_with($path, $suffix)) {
                        return $response;
                    }
                }

                return ['status' => 404, 'body' => '{"error":"no route"}'];
            }
        };
    }

    private function allRoutesOk(): void
    {
        $this->http->routes = [
            '/apiv2/vps' => ['status' => 200, 'body' => json_encode([
                ['vps_id' => '9', 'vps_uuid' => self::UUID, 'vps_hostname' => 'box.example.net', 'vps_ip' => '203.0.113.20', 'vps_status' => 'active'],
            ])],
            '/vps/' . self::UUID => ['status' => 200, 'body' => json_encode(['serviceInfo' => ['vps_status' => 'active', 'vps_server_status' => 'running']])],
            '/reverse_dns' => ['status' => 200, 'body' => json_encode(['ips' => ['203.0.113.20' => 'box.example.net']])],
            '/backups' => ['status' => 200, 'body' => '[]'],
            '/reinstall_os' => ['status' => 200, 'body' => json_encode(['templates' => [['template_file' => 'ubuntu-24.qcow2', 'template_name' => 'Ubuntu', 'template_version' => '24.04']]])],
        ];
    }

    /** @param array<string, mixed> $service @param array<string, mixed> $server */
    private function provisioning(array $service, array $server = []): ProvisioningService
    {
        $db = new ScriptedDatabase();
        $db->on('/FROM services s/', [$service + [
            'id' => 7, 'client_id' => 1, 'server_id' => 3, 'username' => null, 'remote_id' => null,
            'domain' => null, 'hostname' => null, 'password' => null, 'product_name' => 'VPS 2',
            'dedicated_ip' => null, 'assigned_ips' => null, 'status' => 'active',
        ]]);
        $db->on('/FROM servers WHERE id/', [$server + ['id' => 3, 'name' => 'InterServer', 'module_slug' => 'interserver-vps', 'api_username' => '', 'api_token' => 'KEY', 'account_secret' => null, 'active' => 1]]);

        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $modules->register(ProvisioningModule::class, 'interserver-vps', new InterServerVpsProvisioningModule($this->http));

        return new ProvisioningService(new ServiceRepository($db), new ProductRepository($db), new ServerRepository($db), $modules, $hooks, $this->box);
    }

    /** @param array<string, mixed> $result @return array<string, array<string, mixed>> */
    private static function steps(array $result): array
    {
        return array_column($result['steps'], null, 'key');
    }

    public function test_no_assigned_server_is_named_as_the_reason(): void
    {
        $result = $this->provisioning(['server_id' => null])->selfServiceCheck(7);

        $this->assertFalse($result['success']);
        $this->assertFalse(self::steps($result)['server']['ok']);
        $this->assertStringContainsString('Assigned Server', $result['message']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_an_unmatched_service_says_it_is_not_linked(): void
    {
        $this->allRoutesOk();

        $result = $this->provisioning(['hostname' => 'something-else.example'])->selfServiceCheck(7);
        $steps = self::steps($result);

        $this->assertFalse($result['success']);
        $this->assertTrue($steps['account']['ok']);
        $this->assertStringContainsString('1 VPS on the InterServer account', $steps['account']['message']);
        $this->assertFalse($steps['link']['ok']);
        $this->assertStringContainsString('not linked', $result['message']);
        $this->assertArrayNotHasKey('info', $steps, 'Stops before per-VPS calls it cannot address.');
    }

    public function test_a_linked_vps_runs_only_read_calls_and_flags_the_missing_account_password(): void
    {
        $this->allRoutesOk();

        $result = $this->provisioning(['remote_id' => self::UUID])->selfServiceCheck(7);
        $steps = self::steps($result);

        foreach (['server', 'status', 'account', 'link', 'info', 'reverseDnsEntries', 'listBackups', 'osTemplates'] as $key) {
            $this->assertTrue($steps[$key]['ok'], "{$key}: {$steps[$key]['message']}");
        }

        $this->assertStringContainsString('linked by an admin', $steps['link']['message']);
        $this->assertStringContainsString('running', $steps['info']['message']);
        $this->assertStringContainsString('1 operating system', $steps['osTemplates']['message']);
        $this->assertFalse($steps['account_password']['ok']);
        $this->assertSame('OS reinstall, backup restore', $steps['account_password']['affects']);
        $this->assertFalse($result['success']);

        foreach ($this->http->requests as $request) {
            $this->assertSame('GET', $request['method'], 'The check must never change the machine: ' . $request['url']);
            $this->assertDoesNotMatch('#/(restart|start|stop|backup|restore)$#', (string) $request['url']);
        }
    }

    public function test_everything_passes_with_a_saved_account_password(): void
    {
        $this->allRoutesOk();

        $result = $this->provisioning(['hostname' => 'BOX.example.net'], ['account_secret' => $this->box->encrypt('acct-pass')])->selfServiceCheck(7);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringContainsString('matched by hostname', self::steps($result)['link']['message']);
        $this->assertTrue(self::steps($result)['account_password']['ok']);
    }

    public function test_a_failing_read_names_the_buttons_it_breaks(): void
    {
        $this->allRoutesOk();
        $this->http->routes = ['/backups' => ['status' => 400, 'body' => '{"error":"Backups are disabled for this type"}']] + $this->http->routes;

        $steps = self::steps($this->provisioning(['remote_id' => self::UUID])->selfServiceCheck(7));

        $this->assertFalse($steps['listBackups']['ok']);
        $this->assertStringContainsString('Backups are disabled for this type', $steps['listBackups']['message']);
        $this->assertSame('snapshot list, restore', $steps['listBackups']['affects']);
        $this->assertTrue($steps['osTemplates']['ok']);
    }

    public function test_a_linked_id_no_longer_on_the_account_fails(): void
    {
        $this->allRoutesOk();

        $steps = self::steps($this->provisioning(['remote_id' => 'ffff-gone'])->selfServiceCheck(7));

        $this->assertFalse($steps['link']['ok']);
        $this->assertStringContainsString('Link it again', $steps['link']['message']);
    }

    public function test_an_explained_refusal_is_flagged_for_the_client(): void
    {
        $this->allRoutesOk();
        $this->http->routes = ['/restart' => ['status' => 409, 'body' => '{"text":"VPS is not active"}']] + $this->http->routes;

        $result = $this->provisioning(['remote_id' => self::UUID])->power(7, 'restart');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['refused'] ?? false);
        $this->assertSame('VPS is not active', $result['providerMessage']);
    }

    public function test_firewall_blocks_and_destructive_calls_are_not_flagged(): void
    {
        $this->allRoutesOk();
        $this->http->routes = [
            '/restart' => ['status' => 403, 'body' => '<html><title>Attention Required! | Cloudflare</title>Sorry, you have been blocked</html>'],
            '/reinstall_os' => ['status' => 400, 'body' => '{"error":"Invalid password"}'],
        ] + $this->http->routes;
        $svc = $this->provisioning(['remote_id' => self::UUID], ['account_secret' => $this->box->encrypt('acct-pass')]);

        $blocked = $svc->power(7, 'restart');
        $this->assertFalse($blocked['success']);
        $this->assertArrayNotHasKey('refused', $blocked);

        $reinstall = $svc->reinstall(7, 'ubuntu-24.qcow2', '', 'NewRoot12345');
        $this->assertFalse($reinstall['success']);
        $this->assertArrayNotHasKey('refused', $reinstall, 'A 400 on reinstall can mean a wrong account password: that is for staff.');
    }

    private function assertDoesNotMatch(string $pattern, string $subject): void
    {
        $this->assertSame(0, preg_match($pattern, $subject), "{$subject} matched {$pattern}");
    }
}
