<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Billing\ServiceRenewalService;
use CodeVault\Clients\ClientImpersonation;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Reseller\ResellerClientDirectory;
use CodeVault\Reseller\ResellerClientManager;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Session\SessionManager;
use CodeVault\Tests\Support\ScriptedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * A reseller managing its own customers, and anyone (reseller or platform admin)
 * signing in to a store customer's account on the store's own website.
 *
 * The rules under test are the ones that keep stores apart: every read is scoped to the
 * store inside the SQL, a store lifts only the suspensions it made, a sign-in ticket
 * works only on the site it names and only while the customer still belongs there, and
 * an impersonating reseller cannot take the account from the customer.
 */
final class ResellerClientManagementTest extends TestCase
{
    private const STORE = 7;

    /** @var array<string, mixed> */
    private array $sessionBackup = [];

    private mixed $appUrlBackup = null;

    protected function setUp(): void
    {
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];
        $this->appUrlBackup = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://platform.test';
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;

        if ($this->appUrlBackup === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->appUrlBackup;
        }
    }

    // ------------------------------------------------------------ decisions ---

    public function test_only_an_active_service_can_be_suspended(): void
    {
        $this->assertTrue(ResellerClientManager::canSuspend(['status' => 'active']));

        foreach (['pending', 'suspended', 'cancelled', 'terminated'] as $status) {
            $this->assertFalse(ResellerClientManager::canSuspend(['status' => $status]), $status);
        }
    }

    public function test_a_store_lifts_only_a_suspension_it_made(): void
    {
        $byThisStore = ['status' => 'suspended', 'suspended_by_reseller_id' => self::STORE];
        $byPlatform = ['status' => 'suspended', 'suspended_by_reseller_id' => null];
        $byOtherStore = ['status' => 'suspended', 'suspended_by_reseller_id' => 8];

        $this->assertTrue(ResellerClientManager::canLift($byThisStore, self::STORE));
        $this->assertFalse(ResellerClientManager::canLift($byPlatform, self::STORE), 'an overdue-invoice suspension is ours');
        $this->assertFalse(ResellerClientManager::canLift($byOtherStore, self::STORE));
        $this->assertFalse(ResellerClientManager::canLift(['status' => 'active', 'suspended_by_reseller_id' => self::STORE], self::STORE));
        $this->assertFalse(ResellerClientManager::canLift(['status' => 'suspended', 'suspended_by_reseller_id' => 0], 0), 'store 0 is not a store');
    }

    public function test_payment_does_not_lift_a_hold_by_the_customers_own_store(): void
    {
        $this->assertTrue(ServiceRenewalService::isHeldByStore(['suspended_by_reseller_id' => 7, 'client_reseller_id' => 7]));
        $this->assertFalse(ServiceRenewalService::isHeldByStore(['suspended_by_reseller_id' => null, 'client_reseller_id' => 7]), 'our own suspension: payment lifts it');
        $this->assertFalse(ServiceRenewalService::isHeldByStore(['suspended_by_reseller_id' => 7, 'client_reseller_id' => 8]), 'moved to another store: the old mark is stale');
        $this->assertFalse(ServiceRenewalService::isHeldByStore(['suspended_by_reseller_id' => 7, 'client_reseller_id' => null]), 'moved to the platform');
    }

    public function test_terminate_is_offered_only_while_there_is_something_to_remove(): void
    {
        foreach (['pending', 'active', 'suspended'] as $status) {
            $this->assertTrue(ResellerClientManager::canTerminate(['status' => $status]), $status);
        }

        foreach (['cancelled', 'terminated'] as $status) {
            $this->assertFalse(ResellerClientManager::canTerminate(['status' => $status]), $status);
        }
    }

    public function test_a_suspended_store_cannot_change_its_customers(): void
    {
        $this->assertNull(ResellerClientManager::storeError(['status' => 'active']));
        $this->assertNotNull(ResellerClientManager::storeError(['status' => 'suspended']));
    }

    public function test_profile_needs_a_name_and_a_two_letter_country(): void
    {
        $ok = ResellerClientManager::validateProfile([
            'first_name' => '  Ada ',
            'last_name' => 'Obi',
            'country' => 'ng',
            'phone' => '',
        ]);
        $this->assertNull($ok['error']);
        $this->assertSame('Ada', $ok['fields']['first_name']);
        $this->assertSame('NG', $ok['fields']['country']);
        $this->assertNull($ok['fields']['phone'], 'a blank field is stored as NULL');
        $this->assertArrayNotHasKey('email', $ok['fields'], 'the login email is never a profile field');

        $this->assertNotNull(ResellerClientManager::validateProfile(['first_name' => 'Ada', 'last_name' => ''])['error']);
        $this->assertNotNull(ResellerClientManager::validateProfile(['first_name' => 'Ada', 'last_name' => 'Obi', 'country' => 'Nigeria'])['error']);
        $this->assertNotNull(ResellerClientManager::validateProfile(['first_name' => str_repeat('a', 500), 'last_name' => 'Obi'])['error']);
    }

    public function test_nameservers_are_two_to_six_valid_hosts(): void
    {
        $ok = ResellerClientManager::validateNameservers(['NS1.Example.com.', 'ns2.example.com', '', 'ns1.example.com']);
        $this->assertNull($ok['error']);
        $this->assertSame(['ns1.example.com', 'ns2.example.com'], $ok['nameservers'], 'normalised and de-duplicated');

        $this->assertNotNull(ResellerClientManager::validateNameservers(['ns1.example.com'])['error']);
        $this->assertNotNull(ResellerClientManager::validateNameservers(['ns1.example.com', 'not a host'])['error']);
        $this->assertNotNull(ResellerClientManager::validateNameservers(array_map(static fn (int $i): string => "ns{$i}.example.com", range(1, 7)))['error']);
    }

    // ------------------------------------------------------------- scoping ---

    public function test_every_directory_read_is_scoped_to_the_store_in_the_query(): void
    {
        $seen = [];
        $db = (new ScriptedDatabase())->on('/./s', static function (array $bindings, string $sql) use (&$seen): array {
            $seen[] = ['sql' => $sql, 'bindings' => $bindings];

            return [];
        });
        $directory = new ResellerClientDirectory($db);

        $directory->clients(self::STORE, 'ada', 'unpaid', 2);
        $directory->summary(self::STORE);
        $directory->client(self::STORE, 42);
        $directory->services(self::STORE, 42);
        $directory->service(self::STORE, 99);
        $directory->domains(self::STORE, 42);
        $directory->domain(self::STORE, 98);
        $directory->invoices(self::STORE, 42);
        $directory->tickets(self::STORE, 42);

        $this->assertGreaterThanOrEqual(10, count($seen));

        foreach ($seen as $query) {
            $this->assertMatchesRegularExpression('/reseller_id = \?/', $query['sql'], $query['sql']);
            $this->assertContains(self::STORE, array_map('intval', $query['bindings']), $query['sql']);
        }
    }

    public function test_another_stores_customer_is_simply_not_found(): void
    {
        // Rows exist only for store 8's customer; asking as store 7 finds nothing.
        $db = (new ScriptedDatabase())
            ->on('/FROM clients WHERE id = \? AND reseller_id = \?/', static fn (array $b): array => (int) $b[1] === 8 ? [['id' => 42, 'reseller_id' => 8]] : [])
            ->on('/FROM services s JOIN clients c/', static fn (array $b): array => (int) $b[1] === 8 ? [['id' => 99, 'client_id' => 42]] : []);
        $directory = new ResellerClientDirectory($db);

        $this->assertNull($directory->client(self::STORE, 42));
        $this->assertNull($directory->service(self::STORE, 99));
        $this->assertNotNull($directory->client(8, 42));
    }

    // ------------------------------------------------------- impersonation ---

    public function test_a_ticket_for_a_store_customer_points_at_the_store_website(): void
    {
        [$impersonation, $db] = $this->impersonation();

        $result = $impersonation->issue(
            ['id' => 42, 'reseller_id' => self::STORE, 'status' => 'active'],
            'reseller',
            500,
            'Acme Hosting (reseller)',
            'https://platform.test/client/reseller/clients/42'
        );

        $this->assertTrue($result['success']);
        $this->assertMatchesRegularExpression('#^https://acme\.platform\.test/client/impersonate/[a-f0-9]{64}$#', (string) $result['url']);

        $insert = $db->writes[0];
        $this->assertStringContainsString('client_impersonation_tokens', $insert['sql']);
        $token = substr((string) $result['url'], -64);
        $this->assertSame(hash('sha256', $token), $insert['bindings'][0], 'only the hash is stored');
        $this->assertNotContains($token, $insert['bindings'], 'the token itself is never stored');
        $this->assertSame(self::STORE, $insert['bindings'][5], 'the ticket names the store site');
    }

    public function test_a_closed_account_or_unknown_actor_gets_no_ticket(): void
    {
        [$impersonation, $db] = $this->impersonation();

        $this->assertFalse($impersonation->issue(['id' => 42, 'reseller_id' => self::STORE, 'status' => 'closed'], 'admin', 1, 'Admin', '/')['success']);
        $this->assertFalse($impersonation->issue(['id' => 42, 'reseller_id' => self::STORE], 'client', 1, 'X', '/')['success']);
        $this->assertSame([], $db->writes);
    }

    public function test_a_ticket_is_redeemed_only_on_the_site_it_names(): void
    {
        $token = str_repeat('ab', 32);
        [$impersonation, , $current] = $this->impersonation($this->ticketRow($token));

        // Served from the platform's own host: refused.
        $this->assertFalse($impersonation->redeem($token)['success']);
        $this->assertArrayNotHasKey('client_id', $_SESSION);

        // Served from another store: refused.
        $current->set(['id' => 8, 'client_id' => 501, 'slug' => 'other']);
        $this->assertFalse($impersonation->redeem($token)['success']);
        $this->assertArrayNotHasKey('client_id', $_SESSION);
    }

    public function test_redeeming_signs_in_as_the_customer_and_end_restores_the_owner(): void
    {
        $token = str_repeat('cd', 32);
        [$impersonation, , $current] = $this->impersonation($this->ticketRow($token));
        $current->set(['id' => self::STORE, 'client_id' => 500, 'slug' => 'acme']);

        // The store owner happened to be signed in on their own store already.
        $_SESSION['client_id'] = 500;

        $this->assertTrue($impersonation->redeem($token)['success']);
        $this->assertSame(42, $_SESSION['client_id']);

        $active = $impersonation->active();
        $this->assertNotNull($active);
        $this->assertSame('reseller', $active['actor_type']);
        $this->assertSame('Acme Hosting (reseller)', $active['actor_label']);
        $this->assertSame('Ada Obi', $active['client_name']);

        $this->assertSame('https://platform.test/client/reseller/clients/42', $impersonation->end());
        $this->assertSame(500, $_SESSION['client_id'], 'the owner gets their own session back');
        $this->assertArrayNotHasKey(ClientImpersonation::SESSION_KEY, $_SESSION);
    }

    public function test_a_customer_moved_to_another_store_cannot_be_reached_with_an_old_ticket(): void
    {
        $token = str_repeat('ef', 32);
        [$impersonation, , $current] = $this->impersonation($this->ticketRow($token), clientStore: 8);
        $current->set(['id' => self::STORE, 'client_id' => 500, 'slug' => 'acme']);

        $this->assertFalse($impersonation->redeem($token)['success']);
        $this->assertArrayNotHasKey('client_id', $_SESSION);
    }

    public function test_malformed_tokens_never_reach_the_database(): void
    {
        [$impersonation, $db] = $this->impersonation();

        $this->assertFalse($impersonation->redeem('../../etc')['success']);
        $this->assertFalse($impersonation->redeem(str_repeat('Z', 64))['success']);
        $this->assertSame([], $db->writes);
    }

    public function test_impersonation_ends_when_the_session_changes_client(): void
    {
        $data = ['client_id' => 42, 'actor_type' => 'reseller'];

        $this->assertSame($data, ClientImpersonation::activeIn($data, 42));
        $this->assertSame($data, ClientImpersonation::activeIn($data, '42'));
        $this->assertNull(ClientImpersonation::activeIn($data, 43));
        $this->assertNull(ClientImpersonation::activeIn($data, null));
        $this->assertNull(ClientImpersonation::activeIn('junk', 42));
    }

    public function test_a_reseller_signed_in_as_a_customer_cannot_take_the_account(): void
    {
        foreach ([
            ['POST', '/client/account'],
            ['POST', '/client/account/password'],
            ['POST', '/client/account/security-pin'],
            ['POST', '/client/account/security/2fa/enable'],
            ['POST', '/client/payment-methods/3/delete'],
            ['POST', '/client/account/privacy/erase'],
            ['GET', '/client/reseller'],
            ['GET', '/client/reseller/account'],
            ['POST', '/client/reseller/payouts'],
        ] as [$method, $path]) {
            $this->assertTrue(ClientImpersonation::restrictedForReseller($method, $path), "{$method} {$path}");
        }

        foreach ([
            ['GET', '/client/account'],
            ['GET', '/client/dashboard'],
            ['POST', '/client/tickets'],
            ['POST', '/client/invoices/5/pay'],
            ['POST', '/client/services/9/upgrade'],
            ['POST', '/client/accounting'],
        ] as [$method, $path]) {
            $this->assertFalse(ClientImpersonation::restrictedForReseller($method, $path), "{$method} {$path}");
        }
    }

    // ------------------------------------------------------------- helpers ---

    /** @return array<string, mixed> */
    private function ticketRow(string $token): array
    {
        return [
            'token_hash' => hash('sha256', $token),
            'client_id' => 42,
            'actor_type' => 'reseller',
            'actor_id' => 500,
            'actor_label' => 'Acme Hosting (reseller)',
            'site_reseller_id' => self::STORE,
            'return_url' => 'https://platform.test/client/reseller/clients/42',
        ];
    }

    /**
     * @param array<string, mixed>|null $ticket
     * @return array{0: ClientImpersonation, 1: ScriptedDatabase, 2: CurrentReseller}
     */
    private function impersonation(?array $ticket = null, int $clientStore = self::STORE): array
    {
        $db = (new ScriptedDatabase())
            ->on('/FROM resellers WHERE id = \?/', [['id' => self::STORE, 'slug' => 'acme', 'status' => 'active', 'custom_domain' => null, 'domain_verified_at' => null]])
            ->on('/FROM client_impersonation_tokens/', $ticket === null ? [] : [$ticket])
            ->on('/FROM clients c/', [['id' => 42, 'first_name' => 'Ada', 'last_name' => 'Obi', 'reseller_id' => $clientStore, 'status' => 'active']]);

        $config = new Config(dirname(__DIR__, 2));
        $session = new class ($config) extends SessionManager {
            public function regenerate(): void
            {
                // No real session in a unit test; what matters is what lands in $_SESSION.
            }
        };
        $current = new CurrentReseller();
        $stores = new ResellerStoreRepository($db);

        $impersonation = new ClientImpersonation(
            $db,
            $session,
            new ClientRepository($db),
            $current,
            new ResellerStoreLocator($stores, $config),
            $stores,
            $config,
            new ActivityLogger($db)
        );

        return [$impersonation, $db, $current];
    }
}
