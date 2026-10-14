<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Provisioning\CpanelProvisioningModule;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\ScriptedDatabase;
use CodeVault\UsernameChanger\PolicyResolver;
use CodeVault\UsernameChanger\UsernameAvailability;
use CodeVault\UsernameChanger\UsernameChangeRepository;
use CodeVault\UsernameChanger\UsernameChangerSettings;
use CodeVault\UsernameChanger\UsernamePolicy;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Reseller\ResellerLedgerRepository;
use CodeVault\Reseller\ResellerLedgerService;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Tests\Support\CapturingQueue;
use CodeVault\UsernameChanger\UsernameChangeNotifier;
use CodeVault\UsernameChanger\UsernameChangeService;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * cPanel Username Changer (docs/CPANEL_USERNAME_CHANGER_PLAN.md).
 *
 * No MySQL: every collaborator reads through a ScriptedDatabase, so each test is
 * about one DECISION — is this name allowed, is it free, what does this client
 * pay and who earns what, what may a store change — plus a render of every screen
 * with the error handler turned into exceptions, so an undefined key in a view
 * fails here rather than on a customer's page.
 */
final class CpanelUsernameChangerTest extends TestCase
{
    // ------------------------------------------------------------ fixtures

    /** @param array<string, string> $values username_changer.* settings */
    private static function settings(ScriptedDatabase $db, array $values = []): UsernameChangerSettings
    {
        $db->on('/FROM settings WHERE `key`/', static function (array $b) use ($values): array {
            $key = substr((string) $b[0], strlen(UsernameChangerSettings::PREFIX));

            return array_key_exists($key, $values) ? [['value' => $values[$key]]] : [];
        });

        return new UsernameChangerSettings(new SettingsRepository($db));
    }

    /**
     * A service context the way PolicyResolver::forService() selects it.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function ctx(array $over = []): array
    {
        return $over + [
            'id' => 10, 'client_id' => 100, 'product_id' => 7, 'server_id' => 3,
            'username' => 'olduser', 'domain' => 'example.com', 'status' => 'active',
            'product_type' => 'shared', 'product_name' => 'Starter', 'module_slug' => 'cpanel',
            'store_id' => null, 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com',
            'security_pin_hash' => null,
        ];
    }

    /**
     * Wires a store tree: store 50 (owner client 500). With $upline, store 50's
     * owner is itself a customer of store 40 (owner client 400).
     *
     * @param array<string, array<string, mixed>> $policies "scope:id" => row
     */
    private static function resolver(ScriptedDatabase $db, UsernameChangerSettings $s, array $policies = [], bool $upline = false, array $stats = ['n' => 0, 'last' => null]): PolicyResolver
    {
        $db->on('/FROM resellers WHERE id = \?/', static fn (array $b): array => (int) $b[0] === 50
            ? [['id' => 50, 'client_id' => 500, 'brand_name' => 'Store', 'slug' => 'store']]
            : []);
        $db->on('/JOIN resellers u ON u.id = c.reseller_id WHERE c.id = \?/', static fn (array $b): array => $upline && (int) $b[0] === 500
            ? [['id' => 40, 'client_id' => 400]]
            : []);
        $db->on('/FROM username_change_policies WHERE scope = \? AND scope_id = \?/', static fn (array $b): array => isset($policies[$b[0] . ':' . $b[1]])
            ? [$policies[$b[0] . ':' . $b[1]] + ['enabled' => null, 'max_changes' => null, 'cooldown_days' => null, 'approval' => null, 'allow_db_rename' => null, 'client_mode' => null, 'extra_changes' => null, 'fee' => null]]
            : []);
        $db->on('/COUNT\(\*\) AS n, MAX\(completed_at\)/', [$stats]);

        return new PolicyResolver($db, new UsernameChangeRepository($db), $s);
    }

    // ------------------------------------------------------------ local rules

    /** @return array<string, array{0: string, 1: string}> */
    public static function badNames(): array
    {
        return [
            'empty' => ['', 'empty'],
            'upper case' => ['Johnny', 'case'],
            'leading digit' => ['1johnny', 'leading_digit'],
            'symbols' => ['john_ny', 'chars'],
            'too short' => ['joe', 'short'],
            'too long' => ['abcdefghijklmnopq', 'long'],
            'test prefix' => ['testsite', 'test_prefix'],
            'reserved' => ['admin', 'reserved'],
            'custom reserved' => ['acmecorp', 'reserved'],
            'same as now' => ['olduser', 'same'],
        ];
    }

    /** @dataProvider badNames */
    public function test_local_rules_reject_with_a_reason_code(string $name, string $code): void
    {
        $policy = new UsernamePolicy(5, 16, ['AcmeCorp']);

        $this->assertSame($code, $policy->violation($name, 'olduser')[0] ?? null);
    }

    public function test_a_good_name_passes_and_max_length_never_exceeds_cpanels_16(): void
    {
        $this->assertNull((new UsernamePolicy())->violation('newname24', 'olduser'));
        $this->assertSame(16, (new UsernamePolicy(5, 40))->maxLength());
    }

    public function test_suggestions_pass_every_rule_and_are_stable_for_the_same_input(): void
    {
        $policy = new UsernamePolicy();
        $ctx = ['domain' => '9lives-bakery.ng', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current' => 'livesbak'];
        $a = $policy->suggestions('Test', $ctx);

        $this->assertNotEmpty($a);
        foreach ($a as $s) {
            $this->assertNull($policy->violation($s, 'livesbak'), "suggestion {$s} breaks a rule");
        }
        $this->assertSame($a, $policy->suggestions('Test', $ctx));
    }

    // ------------------------------------------------------------ fast precheck

    public function test_fast_check_is_local_db_only_and_reports_each_kind_of_clash(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db, ['first8_rule' => 'on']);
        $db->on('/FROM services WHERE username IN/', static fn (array $b): array => in_array('takenone', $b, true) ? [['u' => 'takenone']] : []);
        $db->on('/FROM username_change_requests WHERE new_username IN/', static fn (array $b): array => in_array('reserved1', $b, true) ? [['u' => 'reserved1']] : []);
        $db->on('/SELECT username AS u FROM username_change_server_accounts/', static fn (array $b): array => in_array('onserver', $b, true) ? [['u' => 'onserver']] : []);
        $db->on('/SELECT prefix8 FROM username_change_server_accounts/', static fn (array $b): array => in_array('longpref', $b, true) ? [['prefix8' => 'longpref']] : []);

        // No ProvisioningService at all: fast() must never need the network.
        $a = new UsernameAvailability($db, $s);
        $service = ['id' => 10, 'server_id' => 3, 'username' => 'olduser'];

        $this->assertSame('taken', $a->fast('takenone', $service)['code']);
        $this->assertSame('pending', $a->fast('reserved1', $service)['code']);
        $this->assertSame('taken', $a->fast('onserver', $service)['code']);
        $this->assertSame('prefix8', $a->fast('longprefix2', $service)['code']);
        $this->assertSame('short', $a->fast('ab', $service)['code']);
        $this->assertTrue($a->fast('freename', $service)['ok']);
        $this->assertTrue($a->fast('  FreeName ', $service)['ok'], 'input is normalised');
        $this->assertSame([], $db->writes, 'the live check never writes');
    }

    public function test_fast_check_never_says_who_holds_a_name(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db);
        $db->on('/FROM services WHERE username IN/', [['u' => 'takenone']]);

        $msg = (new UsernameAvailability($db, $s))->fast('takenone', ['id' => 1, 'server_id' => null, 'username' => 'x'])['message'];

        $this->assertStringNotContainsString('@', $msg);
        $this->assertStringNotContainsString('#', $msg);
    }

    public function test_suggestions_are_checked_in_one_batch_and_taken_ones_are_dropped(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db);
        $calls = 0;
        $db->on('/FROM services WHERE username IN/', static function (array $b) use (&$calls): array {
            $calls++;

            return [['u' => 'adaobi']];
        });

        $out = (new UsernameAvailability($db, $s))->suggest('', ['id' => 1, 'server_id' => null, 'username' => 'olduser'], ['domain' => 'example.com', 'first_name' => 'Ada', 'last_name' => 'Obi']);

        $this->assertNotContains('adaobi', $out);
        $this->assertNotEmpty($out);
        $this->assertSame(1, $calls, 'one query for every candidate together');
    }

    // ------------------------------------------------------------ pricing & margins

    public function test_payment_off_means_free_for_everyone(): void
    {
        $db = new ScriptedDatabase();
        $p = self::resolver($db, self::settings($db, ['fee' => '5.00']), ['store:50' => ['fee' => '9.00']])->resolve(self::ctx(['store_id' => 50]));

        $this->assertFalse($p['pricing']['charge']);
    }

    public function test_platform_customer_pays_the_admin_fee_or_the_product_override(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00']);
        $this->assertSame(5.0, self::resolver($db, $s)->resolve(self::ctx())['pricing']['price']);

        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00']);
        $p = self::resolver($db, $s, ['product:7' => ['fee' => '2.50']])->resolve(self::ctx());
        $this->assertSame(2.5, $p['pricing']['price']);
        $this->assertSame(2.5, $p['pricing']['admin_fee']);
    }

    public function test_a_store_resells_above_the_admin_fee_and_keeps_the_margin(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00']);
        $pr = self::resolver($db, $s, ['store:50' => ['fee' => '8.00']])->resolve(self::ctx(['store_id' => 50]))['pricing'];

        $this->assertTrue($pr['charge']);
        $this->assertSame(8.0, $pr['price']);
        $this->assertSame(5.0, $pr['cost']);
        $this->assertSame(3.0, $pr['store_margin']);
        $this->assertSame(0.0, $pr['upline_margin']);
    }

    public function test_a_store_can_never_charge_below_its_cost_and_unpriced_means_cost(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00']);
        $below = self::resolver($db, $s, ['store:50' => ['fee' => '1.00']])->resolve(self::ctx(['store_id' => 50]))['pricing'];
        $this->assertSame(5.0, $below['price']);
        $this->assertSame(0.0, $below['store_margin']);

        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00']);
        $unpriced = self::resolver($db, $s)->resolve(self::ctx(['store_id' => 50]))['pricing'];
        $this->assertSame(5.0, $unpriced['price']);
    }

    public function test_store_prices_are_ignored_when_the_admin_disallows_resale(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00', 'store_pricing' => '0']);
        $pr = self::resolver($db, $s, ['store:50' => ['fee' => '8.00']])->resolve(self::ctx(['store_id' => 50]))['pricing'];

        $this->assertSame(5.0, $pr['price']);
        $this->assertSame(0.0, $pr['store_margin']);
    }

    public function test_a_sub_reseller_buys_at_its_uplines_price_and_both_earn(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00']);
        $pr = self::resolver($db, $s, ['store:40' => ['fee' => '8.00'], 'store:50' => ['fee' => '12.00']], upline: true)
            ->resolve(self::ctx(['store_id' => 50]))['pricing'];

        $this->assertSame(12.0, $pr['price']);
        $this->assertSame(8.0, $pr['cost'], 'the sub-reseller pays the upline price');
        $this->assertSame(5.0, $pr['upline_cost']);
        $this->assertSame(4.0, $pr['store_margin']);
        $this->assertSame(3.0, $pr['upline_margin']);
    }

    public function test_waived_clients_are_never_charged(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00']);
        $p = self::resolver($db, $s, ['client:100' => ['client_mode' => 'waive']], stats: ['n' => 5, 'last' => date('Y-m-d H:i:s')])->resolve(self::ctx());

        $this->assertFalse($p['pricing']['charge']);
        $this->assertTrue($p['can_request'], 'waive also lifts the limit and cooldown');
    }

    public function test_store_cost_is_the_admin_fee_or_the_uplines_price(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00']);
        $this->assertSame(5.0, self::resolver($db, $s)->storeCost(['id' => 50, 'client_id' => 500]));

        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00']);
        $this->assertSame(8.0, self::resolver($db, $s, ['store:40' => ['fee' => '8.00']], upline: true)->storeCost(['id' => 50, 'client_id' => 500]));

        $db = new ScriptedDatabase();
        $s = self::settings($db, ['fee_enabled' => '1', 'fee' => '5.00']);
        $this->assertSame(5.0, self::resolver($db, $s, ['store:40' => ['fee' => '3.00']], upline: true)->storeCost(['id' => 50, 'client_id' => 500]), 'never below the admin fee');
    }

    // ------------------------------------------------------------ policy layering

    public function test_a_store_can_only_tighten_the_rules(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db, ['max_changes' => '3', 'cooldown_days' => '10', 'allow_db_rename' => '1']);
        $p = self::resolver($db, $s, ['store:50' => ['max_changes' => 1, 'cooldown_days' => 30, 'allow_db_rename' => 0]])->resolve(self::ctx(['store_id' => 50]));
        $this->assertSame(1, $p['max_changes']);
        $this->assertSame(30, $p['cooldown_days']);
        $this->assertFalse($p['allow_db_rename']);

        $db = new ScriptedDatabase();
        $s = self::settings($db, ['max_changes' => '1', 'cooldown_days' => '30']);
        $p = self::resolver($db, $s, ['store:50' => ['max_changes' => 9, 'cooldown_days' => 0, 'enabled' => 1]])->resolve(self::ctx(['store_id' => 50]));
        $this->assertSame(1, $p['max_changes'], 'cannot loosen the limit');
        $this->assertSame(30, $p['cooldown_days'], 'cannot shorten the cooldown');
    }

    public function test_an_uplines_restriction_binds_its_sub_resellers_customers(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db);
        $p = self::resolver($db, $s, ['store:40' => ['enabled' => 0]], upline: true)->resolve(self::ctx(['store_id' => 50]));

        $this->assertFalse($p['eligible']);
        $this->assertSame('disabled', $p['reason']);
    }

    public function test_store_approval_applies_only_when_the_platform_does_not_approve(): void
    {
        $db = new ScriptedDatabase();
        $s = self::settings($db, ['approval' => 'none']);
        $this->assertSame('reseller', self::resolver($db, $s, ['store:50' => ['approval' => 'reseller']])->resolve(self::ctx(['store_id' => 50]))['approval']);

        $db = new ScriptedDatabase();
        $s = self::settings($db, ['approval' => 'admin']);
        $this->assertSame('admin', self::resolver($db, $s, ['store:50' => ['approval' => 'reseller']])->resolve(self::ctx(['store_id' => 50]))['approval']);

        $db = new ScriptedDatabase();
        $s = self::settings($db, ['approval' => 'none', 'store_approval_allowed' => '0']);
        $this->assertSame('none', self::resolver($db, $s, ['store:50' => ['approval' => 'reseller']])->resolve(self::ctx(['store_id' => 50]))['approval']);
    }

    public function test_eligibility_limits_and_cooldown(): void
    {
        $db = new ScriptedDatabase();
        $this->assertSame('not_cpanel', self::resolver($db, self::settings($db))->resolve(self::ctx(['module_slug' => 'interserver']))['reason']);

        $db = new ScriptedDatabase();
        $this->assertSame('stores_off', self::resolver($db, self::settings($db, ['stores_allowed' => '0']))->resolve(self::ctx(['store_id' => 50]))['reason']);

        $db = new ScriptedDatabase();
        $this->assertSame('product_type', self::resolver($db, self::settings($db))->resolve(self::ctx(['product_type' => 'vps']))['reason']);

        $db = new ScriptedDatabase();
        $p = self::resolver($db, self::settings($db))->resolve(self::ctx(['status' => 'suspended']));
        $this->assertTrue($p['eligible']);
        $this->assertFalse($p['can_request']);

        $db = new ScriptedDatabase();
        $p = self::resolver($db, self::settings($db, ['max_changes' => '1']), stats: ['n' => 1, 'last' => '2020-01-01 00:00:00'])->resolve(self::ctx());
        $this->assertSame(0, $p['remaining']);
        $this->assertFalse($p['can_request']);

        $db = new ScriptedDatabase();
        $p = self::resolver($db, self::settings($db, ['max_changes' => '0', 'cooldown_days' => '30']), stats: ['n' => 1, 'last' => date('Y-m-d H:i:s', time() - 86400)])->resolve(self::ctx());
        $this->assertNull($p['remaining'], '0 = unlimited');
        $this->assertNotNull($p['cooldown_until']);
        $this->assertFalse($p['can_request']);

        $db = new ScriptedDatabase();
        $p = self::resolver($db, self::settings($db, ['max_changes' => '1']), ['client:100' => ['extra_changes' => 2]], stats: ['n' => 1, 'last' => '2020-01-01 00:00:00'])->resolve(self::ctx());
        $this->assertSame(2, $p['remaining'], 'extra changes granted by the admin');
    }

    // ------------------------------------------------------------ WHM calls

    /** @var array<string, mixed> */
    private array $server = ['hostname' => 'whm.example.test', 'api_username' => 'root', 'api_token' => 'A1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4', 'api_port' => null, 'use_ssl' => true];

    public function test_verify_new_username_calls_whm_and_separates_unreachable_from_taken(): void
    {
        $http = new FakeHttpClient();
        $m = new CpanelProvisioningModule($http);

        $http->respondWith(200, json_encode(['metadata' => ['result' => 1, 'reason' => 'OK']]));
        $ok = $m->verifyNewUsername(['server' => $this->server, 'new_username' => 'newname']);
        $this->assertStringContainsString('/json-api/verify_new_username?', $http->lastRequest()['url']);
        $this->assertStringContainsString('user=newname', $http->lastRequest()['url']);
        $this->assertTrue($ok['available']);
        $this->assertTrue($ok['reachable']);

        $http->respondWith(200, json_encode(['metadata' => ['result' => 0, 'reason' => 'The name “newname” is already in use.']]));
        $taken = $m->verifyNewUsername(['server' => $this->server, 'new_username' => 'newname']);
        $this->assertFalse($taken['available']);
        $this->assertTrue($taken['reachable']);

        $http->respondWith(0, '');
        $down = $m->verifyNewUsername(['server' => $this->server, 'new_username' => 'newname']);
        $this->assertFalse($down['reachable']);
    }

    public function test_change_username_uses_modifyacct_and_optionally_renames_databases(): void
    {
        $http = new FakeHttpClient();
        $m = new CpanelProvisioningModule($http);
        $http->respondWith(200, json_encode(['metadata' => ['result' => 1, 'reason' => 'OK']]));

        $r = $m->changeUsername(['server' => $this->server, 'username' => 'olduser', 'new_username' => 'newname', 'rename_db' => true]);
        $url = $http->lastRequest()['url'];
        $this->assertTrue($r['success']);
        $this->assertStringContainsString('/json-api/modifyacct?', $url);
        $this->assertStringContainsString('user=olduser', $url);
        $this->assertStringContainsString('newuser=newname', $url);
        $this->assertStringContainsString('rename_database_objects=1', $url);

        $m->changeUsername(['server' => $this->server, 'username' => 'olduser', 'new_username' => 'newname']);
        $this->assertStringNotContainsString('rename_database_objects', $http->lastRequest()['url']);

        $http->respondWith(0, '');
        $this->assertTrue($m->changeUsername(['server' => $this->server, 'username' => 'a', 'new_username' => 'b'])['transport_error'], 'a dropped socket is verified, not assumed failed');
    }

    public function test_account_list_and_engine_detection(): void
    {
        $http = new FakeHttpClient();
        $m = new CpanelProvisioningModule($http);
        $http->respondWith(200, json_encode(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'Alpha', 'domain' => 'a.com'], ['user' => 'beta', 'domain' => 'b.com']]]]));
        $this->assertSame(['alpha' => 'a.com', 'beta' => 'b.com'], $m->listAccounts(['server' => $this->server])['accounts']);

        $http->respondWith(200, json_encode(['metadata' => ['result' => 1], 'data' => ['server' => 'mariadb', 'version' => '10.6']]));
        $this->assertSame('mariadb', $m->databaseEngine(['server' => $this->server]));
        $http->respondWith(200, json_encode(['metadata' => ['result' => 1], 'data' => ['server' => 'mysql', 'version' => '8.0']]));
        $this->assertSame('mysql', $m->databaseEngine(['server' => $this->server]));

        $http->respondWith(0, '');
        $this->assertFalse($m->accountExists(['server' => $this->server, 'username' => 'x'])['known'], 'unreachable is never "missing"');
    }

    // ------------------------------------------------------------ money flow, end to end

    /**
     * A sub-reseller's customer (store 50, whose owner is a customer of store 40)
     * with payment ON: admin fee 5, store 40 resells at 8, store 50 at 12.
     * Confirm → invoice for 12 → paid → store 50 earns 4, store 40 earns 3 →
     * refund → both reversed. Paying twice never credits twice.
     */
    public function test_fee_is_invoiced_after_confirmation_and_margins_are_credited_then_reversed(): void
    {
        $db = new class extends Database {
            /** @var array<string, mixed>|null */
            public ?array $req = null;
            /** @var array<int, array<int, mixed>> */
            public array $ledger = [];
            /** @var array<int, array<int, mixed>> */
            public array $invoices = [];
            /** @var array<int, array{0: string, 1: callable}> */
            private array $on = [];

            public function __construct()
            {
                parent::__construct('', '', '', '', '');
            }

            public function on(string $pattern, array|callable $rows): void
            {
                $this->on[] = [$pattern, is_callable($rows) ? $rows : static fn (): array => $rows];
            }

            public function connection(): \PDO
            {
                throw new \RuntimeException('no database in this test');
            }

            public function select(string $sql, array $bindings = []): array
            {
                if (str_contains($sql, 'FROM username_change_requests')) {
                    if ($this->req === null) {
                        return [];
                    }
                    $r = $this->req;
                    if (preg_match('/WHERE (r\.)?id = \?/', $sql)) {
                        return (int) $bindings[0] === (int) $r['id'] ? [$r + ['domain' => 'example.com', 'current_username' => 'olduser', 'service_status' => 'active', 'first_name' => 'Ada', 'last_name' => 'Obi', 'client_email' => 'ada@example.com']] : [];
                    }
                    if (str_contains($sql, 'WHERE invoice_id = ?')) {
                        return (int) ($r['invoice_id'] ?? 0) === (int) $bindings[0] ? [$r] : [];
                    }
                    if (str_contains($sql, 'confirm_token_hash = ?')) {
                        return ($r['confirm_token_hash'] ?? null) === $bindings[0] ? [$r] : [];
                    }
                    if (str_contains($sql, 'WHERE service_id = ? AND status IN')) {
                        return in_array($r['status'], UsernameChangeRepository::OPEN, true) ? [$r] : [];
                    }

                    return [];
                }
                foreach ($this->on as [$pattern, $handler]) {
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
                if (preg_match('/INSERT INTO username_change_requests \((.+?)\)/', $sql, $m)) {
                    $this->req = array_combine(array_map('trim', explode(',', $m[1])), $bindings) + ['id' => 7, 'invoice_id' => null, 'fee_credited_at' => null, 'fee_reversed_at' => null, 'confirmed_at' => null, 'completed_at' => null, 'decline_reason' => null, 'fee_cost' => null];

                    return '7';
                }
                if (str_contains($sql, 'INSERT INTO reseller_ledger')) {
                    $this->ledger[] = $bindings;
                }
                if (str_contains($sql, 'INSERT INTO invoices ')) {
                    $this->invoices[] = $bindings;

                    return '99';
                }

                return '1';
            }

            public function update(string $sql, array $bindings = []): int
            {
                if ($this->req === null || !str_contains($sql, 'UPDATE username_change_requests SET')) {
                    return 1;
                }
                preg_match('/SET (.+?) WHERE (.+)$/s', $sql, $m);
                $cols = array_map(static fn ($a) => trim(explode('=', $a)[0]), explode(', ', $m[1]));
                $set = array_combine($cols, array_slice($bindings, 0, count($cols)));
                $rest = array_slice($bindings, count($cols) + 1);
                $where = $m[2];
                if (str_contains($where, 'status IN') && !in_array($this->req['status'], $rest, true)) {
                    return 0;
                }
                if (str_contains($where, 'fee_credited_at IS NULL') && $this->req['fee_credited_at'] !== null) {
                    return 0;
                }
                if (str_contains($where, 'fee_credited_at IS NOT NULL') && ($this->req['fee_credited_at'] === null || $this->req['fee_reversed_at'] !== null)) {
                    return 0;
                }
                $this->req = $set + $this->req;

                return 1;
            }

            public function delete(string $sql, array $bindings = []): int
            {
                return 1;
            }

            public function transaction(callable $callback): mixed
            {
                return $callback($this);
            }
        };

        $values = ['fee_enabled' => '1', 'fee' => '5.00', 'execution' => 'queued', 'confirm_methods' => 'email'];
        $db->on('/FROM settings WHERE `key`/', static function (array $b) use ($values): array {
            $key = substr((string) $b[0], strlen(UsernameChangerSettings::PREFIX));

            return array_key_exists($key, $values) ? [['value' => $values[$key]]] : [];
        });
        $db->on('/FROM services s\s+JOIN clients c/', [self::ctx(['store_id' => 50])]);
        $db->on('/FROM resellers WHERE id = \?/', static fn (array $b): array => match ((int) $b[0]) {
            50 => [['id' => 50, 'client_id' => 500, 'brand_name' => 'Store', 'slug' => 'store']],
            40 => [['id' => 40, 'client_id' => 400, 'brand_name' => 'Upline', 'slug' => 'upline']],
            default => [],
        });
        $db->on('/JOIN resellers u ON u.id = c.reseller_id WHERE (c|s)\.id = \?/', static fn (array $b): array => in_array((int) $b[0], [500, 50], true) ? [['id' => 40, 'client_id' => 400]] : []);
        $policies = ['store:40' => '8.00', 'store:50' => '12.00'];
        $db->on('/FROM username_change_policies WHERE scope = \? AND scope_id = \?/', static fn (array $b): array => isset($policies[$b[0] . ':' . $b[1]])
            ? [['enabled' => null, 'max_changes' => null, 'cooldown_days' => null, 'approval' => null, 'allow_db_rename' => null, 'client_mode' => null, 'extra_changes' => null, 'fee' => $policies[$b[0] . ':' . $b[1]]]]
            : []);
        $db->on('/FROM currencies/', [['id' => 1, 'code' => 'NGN', 'symbol' => '₦', 'exchange_rate' => 1, 'is_default' => 1, 'is_pricing' => 1]]);
        $db->on('/FROM clients WHERE id = \?/', [['id' => 100, 'currency_id' => null, 'email' => 'ada@example.com', 'reseller_id' => 50]]);
        $db->on('/SELECT currency_id, currency_rate FROM invoices/', [['currency_id' => null, 'currency_rate' => 1.0]]);

        $settingsRepo = new SettingsRepository($db);
        $settings = new UsernameChangerSettings($settingsRepo);
        $requests = new UsernameChangeRepository($db);
        $currency = new CurrencyService(new CurrencyRepository($db));
        $notifier = new UsernameChangeNotifier(
            new EmailDispatcher(new EmailTemplateRepository($db), new EmailLogRepository($db), new CapturingQueue()),
            $settingsRepo,
            new Config(sys_get_temp_dir() . '/cv-ucn-' . uniqid()),
            $settings
        );
        $ledger = new ResellerLedgerService(new ResellerLedgerRepository($db), new ResellerStoreRepository($db), new ClientRepository($db), $currency, $settingsRepo);
        $service = new UsernameChangeService(
            $requests,
            new PolicyResolver($db, $requests, $settings),
            new UsernameAvailability($db, $settings),
            $settings,
            $notifier,
            $db,
            new InvoiceRepository($db),
            $currency,
            $ledger
        );

        // 1. Request: nothing is charged before the client confirms.
        $res = $service->request(10, 'newname', ['acknowledged' => true, 'method' => 'email', 'ip' => '1.2.3.4']);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame('awaiting_confirmation', $db->req['status']);
        $this->assertSame(50, (int) $db->req['reseller_id']);
        $this->assertSame([], $db->invoices);

        // 2. Confirm from the store's own site (a platform-host click is refused).
        $token = str_repeat('ab', 32);
        $db->req['confirm_token_hash'] = hash('sha256', $token);
        $this->assertFalse($service->confirmByToken($token, null)['ok'], 'a store link only works on the store');
        $confirmed = $service->confirmByToken($token, 50);
        $this->assertTrue($confirmed['ok'], $confirmed['message']);
        $this->assertSame('awaiting_payment', $db->req['status']);
        $this->assertSame(99, (int) $db->req['invoice_id']);
        $this->assertCount(1, $db->invoices);
        $this->assertEquals(12.0, $db->req['fee_amount'], 'the customer pays the store price');
        $this->assertEquals(12.0, $db->req['fee_retail']);
        $this->assertEquals(8.0, $db->req['fee_cost']);
        $this->assertEquals(5.0, $db->req['fee_upline_cost']);
        $this->assertSame([], $db->ledger, 'nothing is credited before payment');

        // 3. Paid: rename queued, both resellers credited.
        $service->invoicePaid(99);
        $this->assertSame('queued', $db->req['status']);
        $this->assertCount(2, $db->ledger);
        [$store, $upline] = $db->ledger;
        $this->assertSame([50, 'adjustment', 4.0], [$store[0], $store[2], $store[3]]);
        $this->assertSame([40, 'upline_margin', 3.0], [$upline[0], $upline[2], $upline[3]]);

        // 4. A repeated payment hook credits nothing more.
        $service->invoicePaid(99);
        $this->assertCount(2, $db->ledger);

        // 5. Refund: both shares reversed, exactly once.
        $service->invoiceRefunded(99);
        $service->invoiceRefunded(99);
        $this->assertCount(4, $db->ledger);
        $this->assertSame([50, 'adjustment', -4.0], [$db->ledger[2][0], $db->ledger[2][2], $db->ledger[2][3]]);
        $this->assertSame([40, 'upline_margin_reversal', -3.0], [$db->ledger[3][0], $db->ledger[3][2], $db->ledger[3][3]]);
    }

    // ------------------------------------------------------------ screens

    private function view(?Database $db = null): View
    {
        $config = new Config(sys_get_temp_dir() . '/cv-ucn-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        if ($db !== null) {
            $container->instance(Database::class, $db);
        }
        App::setContainer($container);

        return new View(dirname(__DIR__, 2) . '/resources/views');
    }

    private function render(View $view, string $template, array $data): string
    {
        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $no, $file, $line);
        });

        try {
            return $view->render($template, $data);
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, mixed> */
    private static function requestRow(array $over = []): array
    {
        return $over + [
            'id' => 7, 'service_id' => 10, 'client_id' => 100, 'reseller_id' => null, 'server_id' => 3,
            'old_username' => 'olduser', 'new_username' => 'newname', 'reason' => 'Rebrand <b>', 'rename_db_objects' => 0,
            'status' => 'pending_approval', 'confirm_method' => 'email', 'confirmed_at' => '2026-10-07 10:00:00',
            'decided_by_type' => null, 'decided_by_id' => null, 'decided_at' => null, 'decline_reason' => null,
            'fee_amount' => '5.00', 'fee_currency_id' => 1, 'fee_retail' => '8.00', 'fee_cost' => '5.00', 'fee_upline_cost' => null,
            'invoice_id' => 99, 'paid_at' => null, 'fee_credited_at' => null, 'fee_reversed_at' => null,
            'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null, 'server_response' => null, 'sync_state' => null,
            'requested_by_type' => 'client', 'requested_by_id' => 100, 'ip' => '1.2.3.4',
            'created_at' => '2026-10-07 09:00:00', 'updated_at' => '2026-10-07 09:00:00', 'completed_at' => null,
            'domain' => 'example.com', 'current_username' => 'olduser', 'service_status' => 'active', 'product_id' => 7,
            'first_name' => 'Ada', 'last_name' => 'Obi', 'client_email' => 'ada@example.com', 'company_name' => null,
            'product_name' => 'Starter', 'server_name' => 'WHM 1', 'server_hostname' => 'whm1.test',
        ];
    }

    public function test_the_client_banner_embeds_rules_and_escapes_everything(): void
    {
        $html = $this->render($this->view(), 'username-changer.banner', ['ucn' => [
            'service_id' => 10, 'current' => 'olduser', 'domain' => 'ex<ample>.com', 'heading' => 'Change cPanel username', 'accent' => '#123456',
            'rules' => (new UsernamePolicy())->toClientRules(), 'can_request' => true, 'blocked_reason' => null, 'remaining' => 1,
            'cooldown_days' => 30, 'approval' => false, 'allow_db_rename' => true, 'require_reason' => false, 'methods' => ['email', 'pin'],
            'fee' => '₦5,000.00', 'open' => null, 'suggestions' => ['adaobi', 'example'],
            'history' => [['id' => 1, 'old' => 'a', 'new' => 'b', 'status' => 'completed', 'label' => 'Completed', 'created' => '2026-01-01 10:00', 'completed' => '2026-01-01 10:01', 'decline_reason' => null, 'invoice_id' => null]],
        ]]);

        $this->assertStringContainsString('data-ucn-open="10"', $html);
        $this->assertStringContainsString('data-ucn-data', $html);
        $this->assertStringContainsString('₦5,000.00', $html);
        $this->assertStringNotContainsString('ex<ample>', $html);
        $this->assertStringContainsString('/assets/js/username-changer.js', $html);
    }

    public function test_the_client_banner_shows_an_open_request(): void
    {
        $html = $this->render($this->view(), 'username-changer.banner', ['ucn' => [
            'service_id' => 10, 'current' => 'olduser', 'domain' => 'example.com', 'heading' => 'Change', 'accent' => '',
            'rules' => (new UsernamePolicy())->toClientRules(), 'can_request' => false, 'blocked_reason' => null, 'remaining' => null,
            'cooldown_days' => 0, 'approval' => true, 'allow_db_rename' => false, 'require_reason' => true, 'methods' => ['email'],
            'fee' => null, 'suggestions' => [], 'history' => [],
            'open' => ['id' => 7, 'new' => 'newname', 'status' => 'awaiting_payment', 'label' => 'Awaiting payment', 'invoice_id' => 99, 'can_cancel' => true, 'can_resend' => false],
        ]]);

        $this->assertStringContainsString('newname', $html);
        $this->assertStringContainsString('/client/invoices/99', $html);
    }

    public function test_the_confirm_page_renders_every_state(): void
    {
        $view = $this->view();
        $req = self::requestRow(['status' => 'awaiting_confirmation']);

        $this->assertStringContainsString('<form', $this->render($view, 'username-changer.confirm', ['token' => 'abc', 'request' => $req, 'expired' => false, 'result' => null, 'heading' => 'Confirm']));
        $this->assertStringContainsString('invalid', $this->render($view, 'username-changer.confirm', ['token' => 'abc', 'request' => null, 'expired' => false, 'result' => null, 'heading' => 'Confirm']));
        $this->render($view, 'username-changer.confirm', ['token' => 'abc', 'request' => $req, 'expired' => true, 'result' => null, 'heading' => 'Confirm']);
        $this->render($view, 'username-changer.confirm', ['token' => 'abc', 'request' => $req, 'expired' => false, 'result' => ['ok' => true, 'message' => 'Done'], 'heading' => 'Confirm']);
    }

    public function test_every_admin_screen_renders(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/username-changer';
        $view = $this->view();
        $base = ['pending' => 2, 'notice' => 'Saved.', 'error' => null];

        $index = $this->render($view, 'username-changer.admin-index', $base + [
            'counts' => ['pending_approval' => 2, 'completed' => 4], 'stats' => ['today' => 1, 'week' => 3, 'completedWeek' => 2, 'mismatch' => 0],
            'rows' => [self::requestRow(), self::requestRow(['id' => 8, 'reseller_id' => 50, 'status' => 'failed', 'sync_state' => 'mismatch'])],
            'status' => 'open', 'q' => '', 'page' => 1, 'feeEnabled' => true,
        ]);
        $this->assertStringContainsString('/admin/username-changer/requests/7', $index);

        $_SERVER['REQUEST_URI'] = '/admin/username-changer/requests/7';
        $detail = $this->render($view, 'username-changer.admin-request', $base + [
            'r' => self::requestRow(['reseller_id' => 50, 'last_error' => 'boom', 'server_response' => '{"x":1}']),
            'store' => ['id' => 50, 'client_id' => 500, 'slug' => 'store', 'brand_name' => 'Store'],
            'events' => [['event' => 'submitted', 'actor_type' => 'client', 'actor_id' => 100, 'ip' => '1.2.3.4', 'detail' => null, 'created_at' => '2026-10-07 09:00:00']],
        ]);
        $this->assertStringContainsString('/admin/resellers/500/customers/100', $detail, 'store customers open via the reseller, never the general client area');
        $this->assertStringContainsString('/requests/7/approve', $detail);
        $this->assertStringContainsString('Rebrand &lt;b&gt;', $detail);

        $this->render($view, 'username-changer.admin-request', $base + ['r' => self::requestRow(['status' => 'failed', 'invoice_id' => null]), 'store' => null, 'events' => []]);

        $_SERVER['REQUEST_URI'] = '/admin/username-changer/manual';
        $manual = $this->render($view, 'username-changer.admin-manual', $base + [
            'service' => ['id' => 10, 'username' => 'olduser', 'domain' => 'example.com', 'status' => 'active', 'server_id' => 3, 'server_name' => 'WHM 1', 'module_slug' => 'cpanel', 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'a@b.c'],
            'history' => [self::requestRow(['status' => 'completed'])], 'result' => ['ok' => true, 'message' => 'Preflight passed'],
            'search' => '', 'matches' => [], 'rules' => (new UsernamePolicy())->toClientRules(), 'input' => 'newname',
        ]);
        $this->assertStringContainsString('value="preflight"', $manual);
        $this->render($view, 'username-changer.admin-manual', $base + [
            'service' => null, 'history' => [], 'result' => null, 'search' => 'ada',
            'matches' => [['id' => 10, 'username' => 'olduser', 'domain' => 'example.com', 'status' => 'active', 'first_name' => 'Ada', 'last_name' => 'Obi']],
            'rules' => (new UsernamePolicy())->toClientRules(), 'input' => '',
        ]);

        $_SERVER['REQUEST_URI'] = '/admin/username-changer/settings';
        $values = UsernameChangerSettings::DEFAULTS;
        $values['fee_enabled'] = '1';
        $settings = $this->render($view, 'username-changer.admin-settings', $base + [
            'values' => $values,
            'products' => [['id' => 7, 'name' => 'Starter', 'type' => 'shared']],
            'productPolicies' => [7 => ['scope_id' => 7, 'enabled' => 1, 'max_changes' => 2, 'cooldown_days' => null, 'approval' => 'admin', 'allow_db_rename' => null, 'fee' => '3.00']],
            'clientPolicies' => [['scope_id' => 100, 'client_mode' => 'waive', 'extra_changes' => null, 'note' => 'VIP', 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'a@b.c']],
            'servers' => [['id' => 3, 'name' => 'WHM 1', 'hostname' => 'whm1.test', 'active' => 1, 'db_engine' => 'mysql', 'db_engine_override' => null, 'account_count' => 120, 'accounts_synced_at' => '2026-10-07 09:00:00', 'last_error' => null]],
            'catalogCode' => 'NGN',
        ]);
        $this->assertStringContainsString('name="fee_enabled"', $settings);
        $this->assertStringContainsString('name="store_pricing"', $settings);
        $this->assertStringContainsString('name="p[7][fee]"', $settings);
        $this->assertStringContainsString('Fee (NGN)', $settings);

        $_SERVER['REQUEST_URI'] = '/admin/username-changer/audit';
        $this->render($view, 'username-changer.admin-audit', $base + [
            'events' => [['request_id' => 7, 'old_username' => 'a', 'new_username' => 'b', 'service_id' => 10, 'event' => 'fee_credited', 'actor_type' => 'system', 'actor_id' => null, 'ip' => null, 'detail' => null, 'created_at' => '2026-10-07 09:00:00']],
            'q' => '',
        ]);
    }

    /** @return array<string, mixed> */
    private static function resellerData(array $fee, array $policy = [], string $approval = 'none'): array
    {
        return [
            'rows' => [self::requestRow(['reseller_id' => 50])], 'counts' => ['pending_approval' => 1, 'completed' => 3],
            'status' => 'open', 'q' => '', 'policy' => $policy,
            'global' => ['max_changes' => 1, 'cooldown_days' => 30, 'approval' => $approval, 'store_approval_allowed' => true],
            'fee' => $fee + ['enabled' => false, 'resale' => true, 'cost' => 5.0, 'costLabel' => '₦5.00', 'price' => null, 'priceLabel' => null, 'currencyCode' => 'NGN', 'catalogCode' => 'NGN'],
            'notice' => null, 'error' => null,
        ];
    }

    public function test_the_reseller_page_lets_a_store_set_its_price_when_payment_is_on(): void
    {
        $_SERVER['REQUEST_URI'] = '/client/reseller/username-requests';
        $html = $this->render($this->view(), 'username-changer.reseller', self::resellerData(['enabled' => true, 'price' => 8.0, 'priceLabel' => '₦8.00'], ['approval' => 'reseller', 'fee' => '8.00']));

        $this->assertStringContainsString('name="fee"', $html);
        $this->assertStringContainsString('min="5.00"', $html);
        $this->assertStringContainsString('3.00 NGN', $html, 'margin = price − cost');
        $this->assertStringContainsString('/client/reseller/username-requests/7/approve', $html);
    }

    public function test_the_reseller_page_hides_pricing_when_payment_is_off_or_resale_is_not_allowed(): void
    {
        $_SERVER['REQUEST_URI'] = '/client/reseller/username-requests';
        $view = $this->view();

        $off = $this->render($view, 'username-changer.reseller', self::resellerData(['enabled' => false]));
        $this->assertStringNotContainsString('name="fee"', $off);
        $this->assertStringContainsString('Free', $off);
        $this->assertStringNotContainsString('/approve', $off, 'no approve buttons unless the store asked to approve');

        $noResale = $this->render($view, 'username-changer.reseller', self::resellerData(['enabled' => true, 'resale' => false]));
        $this->assertStringNotContainsString('name="fee"', $noResale);
        $this->assertStringContainsString('set by the platform', $noResale);

        $adminApproves = $this->render($view, 'username-changer.reseller', self::resellerData(['enabled' => false], [], 'admin'));
        $this->assertStringNotContainsString('name="approval"', $adminApproves);
    }

    public function test_the_reseller_nav_shows_username_requests_only_while_the_add_on_is_on(): void
    {
        $_SERVER['REQUEST_URI'] = '/client/reseller';

        $db = new ScriptedDatabase();
        $db->on('/FROM addon_modules WHERE slug/', [['enabled' => 1]]);
        $this->assertStringContainsString('/client/reseller/username-requests', $this->render($this->view($db), 'partials.reseller-nav', []));

        $db = new ScriptedDatabase();
        $db->on('/FROM addon_modules WHERE slug/', [['enabled' => 0]]);
        $this->assertStringNotContainsString('/client/reseller/username-requests', $this->render($this->view($db), 'partials.reseller-nav', []));

        $db = new ScriptedDatabase();
        $db->on('/FROM addon_modules WHERE slug/', [['enabled' => 1]]);
        $db->on('/FROM settings WHERE `key`/', static fn (array $b): array => $b[0] === 'username_changer.stores_allowed' ? [['value' => '0']] : []);
        $this->assertStringNotContainsString('/client/reseller/username-requests', $this->render($this->view($db), 'partials.reseller-nav', []));
    }
}
