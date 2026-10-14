<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\OrderRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Domains\DomainRepository;
use CodeVault\Reseller\ResellerClientDirectory;
use CodeVault\Reseller\ResellerClientManager;
use CodeVault\Reseller\StoreCustomerAdminRedirect;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Support\App;
use CodeVault\Support\TicketRepository;
use CodeVault\Tests\Support\ScriptedDatabase;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * Strict reseller isolation:
 *
 *  - an account registered on a reseller's website is that reseller's from the INSERT
 *    that creates it;
 *  - a store's owner is never claimed as a customer of their own store;
 *  - the admin's general lists, counts and pickers cover the platform's own customers
 *    only, and the admin's client pages send a store customer to the reseller's page;
 *  - from the reseller's page an admin acts as an admin (not bound by the store's
 *    suspension, may lift any suspension) and every link stays under that reseller.
 */
final class StrictResellerIsolationTest extends TestCase
{
    private ?string $savedAppUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAppUrl = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://platform.test';
    }

    protected function tearDown(): void
    {
        if ($this->savedAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->savedAppUrl;
        }

        parent::tearDown();
    }

    // -- Registration --------------------------------------------------------

    public function test_an_account_registered_on_a_store_is_inserted_as_that_stores(): void
    {
        $this->needsArgon2();
        $db = new ScriptedDatabase();
        (new ClientRepository($db))->create([
            'email' => 'ada@example.com', 'password' => 'secret-pass-1', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'reseller_id' => 7,
        ]);

        $this->assertCount(1, $db->writes);
        $this->assertStringContainsString('INSERT INTO clients (reseller_id,', $db->writes[0]['sql']);
        $this->assertSame(7, $db->writes[0]['bindings'][0]);
    }

    public function test_an_account_registered_on_the_platform_has_no_store(): void
    {
        $this->needsArgon2();
        $db = new ScriptedDatabase();
        $repo = new ClientRepository($db);
        $repo->create(['email' => 'a@example.com', 'password' => 'secret-pass-1', 'first_name' => 'A', 'last_name' => 'B']);
        $repo->create(['email' => 'b@example.com', 'password' => 'secret-pass-1', 'first_name' => 'A', 'last_name' => 'B', 'reseller_id' => 0]);

        $this->assertNull($db->writes[0]['bindings'][0]);
        $this->assertNull($db->writes[1]['bindings'][0]);
    }

    public function test_a_store_owner_is_never_claimed_by_their_own_store(): void
    {
        $db = new ScriptedDatabase();
        (new ClientRepository($db))->setResellerIfUnclaimed(5, 7);

        $sql = $db->writes[0]['sql'];
        $this->assertStringContainsString('reseller_id IS NULL', $sql);
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('r.client_id = clients.id', $sql);
        $this->assertContains(7, $db->writes[0]['bindings']);
    }

    // -- Admin lists cover the platform's own customers only -----------------

    public function test_admin_client_list_counts_and_pickers_exclude_store_customers(): void
    {
        $seen = [];
        $db = (new ScriptedDatabase())->on('/./s', static function (array $bindings, string $sql) use (&$seen): array {
            $seen[] = $sql;

            return [];
        });
        $repo = new ClientRepository($db);

        $repo->paginate('ada', 1, 20, ['store' => '7']);
        $repo->countAll();
        $repo->countNewThisMonth();
        $repo->search('ada');

        $this->assertNotSame([], $seen);
        foreach ($seen as $sql) {
            $this->assertStringContainsString(ClientRepository::PLATFORM_ONLY, $sql, 'every admin client read is platform-only: ' . $sql);
        }
    }

    public function test_admin_services_domains_invoices_orders_and_tickets_lists_exclude_store_rows(): void
    {
        $seen = [];
        $db = (new ScriptedDatabase())->on('/./s', static function (array $bindings, string $sql) use (&$seen): array {
            $seen[] = $sql;

            return [];
        });

        (new ServiceRepository($db))->paginate();
        (new DomainRepository($db))->paginate();
        (new InvoiceRepository($db))->paginate();
        (new OrderRepository($db))->paginate();
        $clientScoped = count($seen);
        (new TicketRepository($db))->paginate();
        (new TicketRepository($db))->countOpen();

        $this->assertGreaterThan(0, $clientScoped);
        foreach (array_slice($seen, 0, $clientScoped) as $sql) {
            $this->assertStringContainsString(ClientRepository::PLATFORM_ONLY, $sql, $sql);
        }
        foreach (array_slice($seen, $clientScoped) as $sql) {
            $this->assertMatchesRegularExpression('/\breseller_id IS NULL\b/', $sql, $sql);
        }
    }

    public function test_store_orders_are_read_through_the_store(): void
    {
        $seen = [];
        $db = (new ScriptedDatabase())->on('/./s', static function (array $bindings, string $sql) use (&$seen): array {
            $seen[] = [$sql, $bindings];

            return str_contains($sql, 'GROUP BY')
                ? [['store_id' => 7, 'owner_client_id' => 5, 'brand_name' => null, 'slug' => 'acme', 'pending' => '3']]
                : [];
        });
        $directory = new ResellerClientDirectory($db);

        $directory->storeOrders(7);
        $directory->orders(7, 42);
        $directory->pendingOrderCount(7);
        $pending = $directory->pendingOrdersByStore();

        foreach (array_slice($seen, 0, 3) as [$sql, $bindings]) {
            $this->assertStringContainsString('c.reseller_id = ?', $sql);
            $this->assertContains(7, $bindings);
        }
        $this->assertSame([['store_id' => 7, 'owner_client_id' => 5, 'brand_name' => null, 'slug' => 'acme', 'pending' => 3]], $pending);
    }

    // -- The admin's client pages send a store customer to the reseller ------

    public function test_admin_client_urls_for_a_store_customer_go_to_the_resellers_page(): void
    {
        $db = (new ScriptedDatabase())->on('/JOIN resellers r ON r.id = c.reseller_id/', static fn (array $bindings): array => (int) $bindings[0] === 42 ? [['owner_client_id' => 5]] : []);
        $redirect = new StoreCustomerAdminRedirect($db);

        $this->assertSame('/admin/resellers/5/customers/42', $redirect->targetFor('/admin/clients/42'));
        $this->assertSame('/admin/resellers/5/customers/42', $redirect->targetFor('/admin/clients/42/edit'));
        $this->assertSame('/admin/resellers/5/customers/42', $redirect->targetFor('/admin/clients/42/login-as'));
        $this->assertNull($redirect->targetFor('/admin/clients/43'), 'a platform customer opens as usual');
        $this->assertNull($redirect->targetFor('/admin/clients'));
        $this->assertNull($redirect->targetFor('/admin/clients/export'));
        $this->assertNull($redirect->targetFor('/admin/clients/420x'));
        $this->assertNull($redirect->targetFor('/admin/services/42'));
        $this->assertNull(StoreCustomerAdminRedirect::clientIdIn('/client/clients/42'));
    }

    // -- The admin actor -------------------------------------------------------

    public function test_an_admin_acts_as_admin_and_may_lift_any_suspension(): void
    {
        $admin = ResellerClientManager::adminActor(['id' => 3, 'display_name' => 'Root', 'username' => 'root']);
        $reseller = ['id' => 5, 'email' => 'owner@example.com'];

        $this->assertSame(['id' => 3, 'actor_type' => 'admin', 'label' => 'Root'], $admin);
        $this->assertTrue(ResellerClientManager::isAdminActor($admin));
        $this->assertFalse(ResellerClientManager::isAdminActor($reseller));
        $this->assertSame('Admin', ResellerClientManager::adminActor(['id' => 1])['label']);

        $byPlatform = ['status' => 'suspended', 'suspended_by_reseller_id' => null];
        $byStore = ['status' => 'suspended', 'suspended_by_reseller_id' => 7];
        $active = ['status' => 'active', 'suspended_by_reseller_id' => null];

        $this->assertTrue(ResellerClientManager::canLiftAs($byPlatform, 7, $admin));
        $this->assertTrue(ResellerClientManager::canLiftAs($byStore, 7, $admin));
        $this->assertFalse(ResellerClientManager::canLiftAs($active, 7, $admin));
        $this->assertFalse(ResellerClientManager::canLiftAs($byPlatform, 7, $reseller), 'a store never lifts the platform\'s suspension');
        $this->assertTrue(ResellerClientManager::canLiftAs($byStore, 7, $reseller));
    }

    // -- Views -------------------------------------------------------------------

    public function test_the_admin_customer_list_stays_under_the_reseller_and_shows_ids(): void
    {
        $html = $this->render('reseller.client-clients', [
            'store' => ['id' => 7, 'client_id' => 5, 'slug' => 'acme', 'brand_name' => 'Acme <Hosting>', 'status' => 'active'],
            'owner' => ['id' => 5, 'first_name' => 'Rex', 'last_name' => 'Seller', 'email' => 'rex@example.com'],
            'mode' => 'admin',
            'baseUrl' => '/admin/resellers/5/customers',
            'summary' => ['customers' => 1, 'services_active' => 0, 'services_suspended' => 0, 'domains' => 0, 'invoices_unpaid' => 0],
            'results' => ['data' => [$this->listRow(42)], 'total' => 1, 'page' => 1, 'perPage' => 25],
            'orders' => [['id' => 900, 'client_id' => 42, 'status' => 'pending', 'total' => '10.00', 'currency_id' => null, 'currency_rate' => '1', 'created_at' => '2026-10-01 10:00:00', 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com']],
            'orderMoney' => static fn (array $o): string => '$' . $o['total'],
            'search' => '',
            'filter' => 'all',
            'storeError' => null,
            'notice' => null,
            'error' => null,
        ]);

        $this->assertStringContainsString('Reseller ID 7', $html);
        $this->assertStringContainsString('User ID 5', $html);
        $this->assertStringContainsString('href="/admin/resellers/5/customers/42"', $html);
        $this->assertStringContainsString('action="/admin/resellers/5/customers/42/login"', $html);
        $this->assertStringContainsString('action="/admin/resellers/5/login"', $html);
        $this->assertStringContainsString('href="/admin/orders/900"', $html);
        $this->assertStringContainsString('Review &amp; accept', $html);
        $this->assertStringNotContainsString('/client/reseller/clients', $html);
        $this->assertStringNotContainsString('/admin/clients/', $html, 'no way back into the general client area');
        $this->assertStringNotContainsString('Acme <Hosting>', $html);
    }

    public function test_the_admin_customer_page_offers_admin_actions_under_the_reseller(): void
    {
        $html = $this->render('reseller.client-client', [
            'store' => ['id' => 7, 'client_id' => 5, 'slug' => 'acme', 'brand_name' => 'Acme', 'status' => 'suspended'],
            'owner' => null,
            'client' => ['id' => 42, 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com', 'company_name' => '', 'status' => 'active', 'created_at' => '2026-01-02 00:00:00'],
            'actor' => ResellerClientManager::adminActor(['id' => 3, 'display_name' => 'Root']),
            'mode' => 'admin',
            'baseUrl' => '/admin/resellers/5/customers',
            'services' => [[
                'id' => 11, 'product_name' => 'Starter', 'status' => 'suspended', 'suspended_by_reseller_id' => null,
                'suspension_reason' => 'Abuse', 'domain' => 'ada.test', 'hostname' => '', 'username' => '', 'amount' => '5.00',
                'billing_cycle' => 'monthly', 'next_due_date' => '2026-11-01', 'server_id' => 1,
            ]],
            'domains' => [],
            'invoices' => [['id' => 77, 'status' => 'unpaid', 'total' => '5.00', 'currency_id' => null, 'currency_rate' => '1', 'due_date' => '2026-10-10', 'paid_at' => null, 'created_at' => '2026-10-01']],
            'tickets' => [['id' => 31, 'subject' => 'Help', 'status' => 'open', 'updated_at' => '2026-10-01 10:00:00', 'escalated_at' => null]],
            'orders' => [['id' => 900, 'status' => 'pending', 'total' => '5.00', 'currency_id' => null, 'currency_rate' => '1', 'created_at' => '2026-10-01']],
            'serviceMoney' => static fn (float $a): string => '$' . number_format($a, 2),
            'invoiceMoney' => static fn (array $i): string => '$' . $i['total'],
            'storeError' => null,
            'storeNote' => 'This reseller\'s store is suspended.',
            'notice' => null,
            'error' => null,
        ]);

        $this->assertStringContainsString('User ID 42', $html);
        $this->assertStringContainsString('Reseller ID 7', $html);
        $this->assertStringContainsString('action="/admin/resellers/5/customers/42/services/11/unsuspend"', $html, 'an admin lifts the platform\'s own suspension');
        $this->assertStringContainsString('action="/admin/resellers/5/customers/42/profile"', $html);
        $this->assertStringContainsString('href="/admin/services/11"', $html);
        $this->assertStringContainsString('href="/admin/invoices/77"', $html);
        $this->assertStringContainsString('href="/admin/orders/900"', $html);
        $this->assertStringContainsString('/admin/resellers/migrations/review?client=42', $html);
        $this->assertStringContainsString('store is suspended', $html);
        $this->assertStringNotContainsString('/client/reseller/', $html, 'no links into the reseller\'s own area');
        $this->assertStringNotContainsString('Contact support to lift', $html);
    }

    // ------------------------------------------------------------------------

    private function needsArgon2(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('This PHP build has no Argon2 (ClientRepository::create hashes with it).');
        }
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): string
    {
        $_SERVER['REQUEST_URI'] = '/admin/resellers/5/customers';
        $config = new Config(sys_get_temp_dir() . '/codevault-iso-noenv-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        App::setContainer($container);

        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $no, $file, $line);
        });

        try {
            return (new View(dirname(__DIR__, 2) . '/resources/views'))->render($template, $data);
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, mixed> */
    private function listRow(int $id): array
    {
        return [
            'id' => $id, 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com', 'company_name' => '',
            'status' => 'active', 'created_at' => '2026-01-02 00:00:00', 'services_total' => 1, 'services_active' => 1,
            'services_suspended' => 0, 'domains_total' => 0, 'invoices_unpaid' => 0,
        ];
    }
}
