<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ModuleManager;
use CodeVault\Provisioning\CpanelProvisioningModule;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\UsernameChanger\UsernameAvailability;
use CodeVault\UsernameChanger\UsernameChangerSettings;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * The live username check must ask the hosting server, not just our records:
 * the local copy of WHM's account list is filled by cron and can be stale or
 * empty, and "free in our records" was being shown as "available" for names
 * WHM then rejected. These tests run the real ProvisioningService and cPanel
 * module against a scripted WHM.
 */
final class UsernameLiveCheckTest extends TestCase
{
    private FakeHttpClient $http;   // long-timeout client (listaccts, modifyacct…)
    private FakeHttpClient $quick;  // short-timeout client (verify_new_username)
    private LiveCheckDb $db;

    /** @var array<string, mixed> */
    private array $service = ['id' => 7, 'server_id' => 3, 'username' => 'olduser'];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->quick = new FakeHttpClient();
        $this->db = new LiveCheckDb();
        $this->db->on('/FROM services s/', [[
            'id' => 7, 'client_id' => 1, 'server_id' => 3, 'username' => 'olduser', 'remote_id' => null,
            'domain' => 'example.com', 'hostname' => null, 'password' => null, 'product_name' => 'Shared',
            'dedicated_ip' => null, 'assigned_ips' => null, 'status' => 'active',
        ]]);
        $this->db->on('/FROM servers WHERE id/', [[
            'id' => 3, 'name' => 'WHM', 'module_slug' => 'cpanel', 'hostname' => 'whm.example.test',
            'api_username' => 'root', 'api_token' => 'A1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4', 'api_port' => null,
            'use_ssl' => 1, 'account_secret' => null, 'active' => 1,
        ]]);
    }

    private function availability(): UsernameAvailability
    {
        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $modules->register(ProvisioningModule::class, 'cpanel', new CpanelProvisioningModule($this->http, $this->quick));
        $provisioning = new ProvisioningService(new ServiceRepository($this->db), new ProductRepository($this->db), new ServerRepository($this->db), $modules, $hooks);

        return new UsernameAvailability($this->db, new UsernameChangerSettings(new SettingsRepository($this->db)), $provisioning);
    }

    private static function whm(int $result, string $reason = 'OK'): string
    {
        return (string) json_encode(['metadata' => ['result' => $result, 'reason' => $reason]]);
    }

    public function test_a_name_free_in_our_records_but_existing_on_whm_is_taken(): void
    {
        // The reported bug: empty local account copy (cron never ran), WHM has the account.
        $this->quick->respondWith(200, self::whm(0, 'The account “acmehost” already exists.'));

        $r = $this->availability()->live('acmehost', $this->service);

        $this->assertFalse($r['ok']);
        $this->assertSame('server', $r['code']);
        $this->assertSame('That username is already taken on the server.', $r['message']);
        $this->assertStringContainsString('/json-api/verify_new_username?', $this->quick->lastRequest()['url']);
        $this->assertStringContainsString('user=acmehost', $this->quick->lastRequest()['url']);
        $this->assertSame([], $this->http->requests, 'the check uses the short-timeout client only');
        $this->assertNotNull($this->db->write('/INSERT IGNORE INTO username_change_server_accounts/'), 'remembered, so the next check is local');
        $this->assertContains('acmehost', $this->db->write('/INSERT IGNORE INTO username_change_server_accounts/')['bindings']);
    }

    public function test_a_name_whm_accepts_is_available(): void
    {
        $this->quick->respondWith(200, self::whm(1));

        $r = $this->availability()->live('acmehost', $this->service);

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $this->quick->requests);
        $this->assertSame([], $this->db->writes, 'a free name writes nothing');
    }

    public function test_an_unreachable_server_is_never_reported_as_available(): void
    {
        $this->quick->respondWith(0, '');

        $r = $this->availability()->live('acmehost', $this->service);

        $this->assertFalse($r['ok']);
        $this->assertSame('unverified', $r['code']);
        $this->assertStringContainsString('try again', $r['message']);
        $this->assertSame([], $this->db->writes);
    }

    public function test_names_already_known_to_be_taken_never_reach_whm(): void
    {
        $this->db->on('/SELECT username AS u FROM username_change_server_accounts/', [['u' => 'acmehost']]);
        $this->db->on('/FROM services WHERE username IN/', static fn (array $b): array => in_array('siblingx', $b, true) ? [['u' => 'siblingx']] : []);
        $a = $this->availability();

        $this->assertSame('taken', $a->live('acmehost', $this->service)['code']);
        $this->assertSame('taken', $a->live('siblingx', $this->service)['code']);
        $this->assertSame('leading_digit', $a->live('1abcde', $this->service)['code']);
        $this->assertSame([], $this->quick->requests, 'settled locally in about a millisecond');
    }

    public function test_other_whm_rejections_are_reported_but_not_cached_as_accounts(): void
    {
        $this->quick->respondWith(200, self::whm(0, 'The name is reserved by the system.'));

        $r = $this->availability()->live('acmehost', $this->service);

        $this->assertSame('server', $r['code']);
        $this->assertStringContainsString('reserved', $r['message']);
        $this->assertNull($this->db->write('/username_change_server_accounts/'));
    }

    public function test_deep_and_live_are_the_same_check(): void
    {
        $this->quick->respondWith(200, self::whm(0, 'already exists'));

        $this->assertSame($this->availability()->live('acmehost', $this->service), $this->availability()->deep('acmehost', $this->service));
    }

    public function test_a_never_synced_server_is_refreshed_on_demand(): void
    {
        $this->http->respondWith(200, (string) json_encode(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'acmehost', 'domain' => 'acme.test'], ['user' => 'beta', 'domain' => 'b.test']]]]));

        $this->assertTrue($this->availability()->ensureFresh(3));

        $this->assertNotNull($this->db->write('/INSERT IGNORE INTO username_change_servers/'), 'placeholder row so the claim can win');
        $claim = $this->db->write('/UPDATE username_change_servers SET updated_at = \? WHERE server_id = \? AND updated_at </');
        $this->assertNotNull($claim, 'one conditional UPDATE claims the refresh');
        $this->assertStringContainsString('/json-api/listaccts', $this->http->requests[0]['url']);
        $accounts = $this->db->write('/INSERT IGNORE INTO username_change_server_accounts/');
        $this->assertNotNull($accounts);
        $this->assertContains('acmehost', $accounts['bindings']);
    }

    public function test_a_fresh_copy_is_not_refreshed(): void
    {
        $this->db->on('/SELECT accounts_synced_at FROM username_change_servers/', [['accounts_synced_at' => date('Y-m-d H:i:s', time() - 60)]]);

        $this->assertFalse($this->availability()->ensureFresh(3));
        $this->assertSame([], $this->http->requests);
        $this->assertSame([], $this->db->writes);
    }

    public function test_a_concurrent_refresh_claim_is_not_repeated(): void
    {
        $this->db->on('/SELECT accounts_synced_at FROM username_change_servers/', [['accounts_synced_at' => date('Y-m-d H:i:s', time() - 3600)]]);
        $this->db->claimRows = 0; // another request claimed it a moment ago

        $this->assertFalse($this->availability()->ensureFresh(3));
        $this->assertSame([], $this->http->requests, 'no second listaccts');
    }

    public function test_the_kernel_gives_the_cpanel_module_a_short_timeout_client_for_checks(): void
    {
        $kernel = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Kernel.php');

        $this->assertMatchesRegularExpression('/new CpanelProvisioningModule\(\s*new CurlHttpClient\(timeoutSeconds: 300[^)]*\),\s*new CurlHttpClient\(timeoutSeconds: (\d)\b/', $kernel);
    }

    public function test_the_warm_route_exists_and_the_check_releases_the_session(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/routes/username-changer.php');
        $controller = (string) file_get_contents($root . '/core/UsernameChanger/ClientUsernameController.php');

        $this->assertStringContainsString("'/client/services/{id}/username/warm', [ClientUsernameController::class, 'warm']", $routes);
        $this->assertStringContainsString('$this->availability->live($name, $ctx)', $controller);
        $this->assertStringContainsString('$this->session->release();', $controller);
        $this->assertStringContainsString("'unverified' ? 'no-store'", $controller);
    }
}

/**
 * A Database that answers selects from scripted handlers and records writes —
 * like ScriptedDatabase, plus statement() (used by the account refresh) and a
 * configurable row count for the refresh claim.
 */
final class LiveCheckDb extends Database
{
    /** @var array<int, array{0: string, 1: callable}> */
    private array $handlers = [];

    /** @var array<int, array{sql: string, bindings: array<int, mixed>}> */
    public array $writes = [];

    public int $claimRows = 1;

    public function __construct()
    {
        parent::__construct('', '', '', '', '');
    }

    public function on(string $pattern, array|callable $rows): self
    {
        array_unshift($this->handlers, [$pattern, is_callable($rows) ? $rows : static fn (): array => $rows]);

        return $this;
    }

    /** @return array{sql: string, bindings: array<int, mixed>}|null */
    public function write(string $pattern): ?array
    {
        foreach ($this->writes as $w) {
            if (preg_match($pattern, $w['sql']) === 1) {
                return $w;
            }
        }

        return null;
    }

    public function select(string $sql, array $bindings = []): array
    {
        foreach ($this->handlers as [$pattern, $handler]) {
            if (preg_match($pattern, $sql) === 1) {
                return array_values($handler($bindings, $sql));
            }
        }

        return [];
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        return $this->select($sql, $bindings)[0] ?? null;
    }

    public function insert(string $sql, array $bindings = []): string
    {
        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];

        return '1';
    }

    public function update(string $sql, array $bindings = []): int
    {
        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];

        return str_contains($sql, 'updated_at <') ? $this->claimRows : 1;
    }

    public function delete(string $sql, array $bindings = []): int
    {
        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];

        return 1;
    }

    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];

        return new PDOStatement();
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }
}
