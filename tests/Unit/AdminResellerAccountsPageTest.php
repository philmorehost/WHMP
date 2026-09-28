<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Request;
use CodeVault\Reseller\AdminResellerAccountsController;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerLedgerRepository;
use CodeVault\Reseller\ResellerLedgerService;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Staff\RoleRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;
use DateTimeImmutable;

/**
 * The page the reseller account is actually read from.
 *
 * ResellerLedgerTest proves the money rules and ResellerLedgerWiringTest proves
 * the ledger is populated. Neither says anything about whether a figure ever
 * reaches a screen — and a balance nobody can see is not a feature, it is a
 * column. So this drives the real controller and asserts on the rendered HTML.
 *
 * THE ONE ASSERTION THAT MATTERS. The page must never present the balance as if
 * it were available. Those two numbers differ by a month of sales, and a page
 * that shows one figure labelled "available" would overstate every account by
 * exactly the amount a customer can still charge back. So the central test puts
 * one receipt INSIDE the holding period and one OUTSIDE it and asserts both
 * distinct figures reach the page: 120 owed, 100 available. Neither number can
 * be produced by the other, so the check cannot pass by accident.
 */
final class AdminResellerAccountsPageTest extends DatabaseTestCase
{
    private AdminResellerAccountsController $controller;
    private SessionManager $session;
    private SettingsRepository $settings;
    private ClientRepository $clients;
    private ResellerLedgerService $ledger;
    private ResellerStoreRepository $storeRepo;
    private int $superAdminId;
    private int $plainAdminId;
    private int $managerAdminId;
    private int $resellerClientId;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $admins = new AdminRepository($this->db);
        $roles = new RoleRepository($this->db);

        // The gate hangs off ROLES: a super-admin bypasses it, a role explicitly
        // granted resellers.manage passes, and one with neither fails. All three
        // are exercised so the check is shown to be keyed on the permission
        // rather than on "has some role at all".
        $superRoleId = $roles->create('Super Admin', true, []);
        $supportRoleId = $roles->create('Support', false, []);
        $managerRoleId = $roles->create('Reseller Manager', false, [PermissionRegistry::RESELLERS_MANAGE]);

        $this->superAdminId = $this->insertAdmin('root', 'root@example.test', $superRoleId, $now);
        $this->plainAdminId = $this->insertAdmin('support', 'support@example.test', $supportRoleId, $now);
        $this->managerAdminId = $this->insertAdmin('manager', 'manager@example.test', $managerRoleId, $now);

        $configDir = sys_get_temp_dir() . '/codevault-accounts-page-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $config = new Config($configDir);
        $this->session = new SessionManager($config);

        $this->settings = new SettingsRepository($this->db);
        $this->clients = new ClientRepository($this->db);

        // The admin layout renders brand_name(), csrf_field() and the nav, all of
        // which resolve from the service locator — so a hand-built controller test
        // has to pin them to THIS database, or the page renders against the
        // application's.
        $container = new Container();
        $container->instance(SessionManager::class, $this->session);
        $container->instance(Config::class, $config);
        $container->instance(\CodeVault\Database::class, $this->db);
        $container->instance(SettingsRepository::class, $this->settings);
        $container->bind(CsrfToken::class);
        App::setContainer($container);

        $currency = new CurrencyService(new CurrencyRepository($this->db));
        $this->storeRepo = new ResellerStoreRepository($this->db);
        $stores = new ResellerStoreService(
            $this->storeRepo,
            new ResellerStoreLocator($this->storeRepo, $config),
            new DomainVerifier()
        );

        $this->resellerClientId = $this->clients->create([
            'email' => 'store-owner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Store',
            'last_name' => 'Owner',
        ]);

        $this->storeId = (int) $stores->openForClient($this->resellerClientId, 'Acme')['store']['id'];

        $this->ledger = new ResellerLedgerService(
            new ResellerLedgerRepository($this->db),
            $this->storeRepo,
            $this->clients,
            $currency,
            $this->settings
        );

        $this->controller = new AdminResellerAccountsController(
            new AuthGuard($this->session, $admins, new RoleRepository($this->db)),
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->session,
            $this->settings,
            $this->ledger,
            $this->storeRepo,
            $currency,
            new ActivityLogger($this->db)
        );
    }

    // -------------------------------------------------------------- the page

    public function test_the_page_shows_what_is_owed_and_what_is_available_as_two_different_numbers(): void
    {
        $this->signInAsSuperAdmin();

        // One receipt that has cleared its holding period, one that has not. The
        // holding period is 30 days (the seeded default).
        $this->ledger->accrueStoreReceipt(
            $this->paidStoreOrder(100.0, '2026-01-01 09:00:00'),
            (new DateTimeImmutable('-60 days'))->format('Y-m-d H:i:s')
        );
        $this->ledger->accrueStoreReceipt(
            $this->paidStoreOrder(20.0, '2026-01-01 10:00:00'),
            (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')
        );

        $body = (string) $this->controller->index($this->request())->body();

        // 120 owed, 100 available. Each figure is reachable only if the page is
        // reporting the right one, so this cannot pass by showing a single number.
        $this->assertStringContainsString('120.00', $body, 'the balance owed is missing from the page');
        $this->assertStringContainsString('100.00', $body, 'the withdrawable amount is missing from the page');
        $this->assertStringContainsString('acme', strtolower($body));
    }

    public function test_the_page_explains_the_holding_period_rather_than_leaving_two_numbers_unexplained(): void
    {
        $this->signInAsSuperAdmin();
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(75.0, '2026-01-01 09:00:00'));

        $body = (string) $this->controller->index($this->request())->body();

        // A reader who cannot tell why the two figures differ will read the smaller
        // one as an error. The page has to say what the difference IS.
        $this->assertStringContainsString('30-day', $body);
        $this->assertStringContainsString('holding period', $body);
    }

    public function test_the_page_says_nothing_has_been_earned_rather_than_showing_a_bare_zero(): void
    {
        $this->signInAsSuperAdmin();

        $body = (string) $this->controller->index($this->request())->body();

        $this->assertStringContainsString('No store has earned anything yet', $body);
    }

    public function test_the_page_is_forbidden_without_the_resellers_permission(): void
    {
        $this->signIn($this->plainAdminId);

        $this->assertSame(403, $this->controller->index($this->request())->status());
    }

    public function test_the_page_is_allowed_for_a_role_granted_the_permission(): void
    {
        $this->signIn($this->managerAdminId);

        $this->assertSame(200, $this->controller->index($this->request())->status());
    }

    // ------------------------------------------------------------ one store

    public function test_the_detail_page_shows_the_entries_behind_the_balance(): void
    {
        $this->signInAsSuperAdmin();
        $this->ledger->accrueStoreReceipt(
            $this->paidStoreOrder(90.0, '2026-01-01 09:00:00'),
            '2026-01-01 09:00:00'
        );

        $response = $this->controller->show($this->request(), ['clientId' => (string) $this->resellerClientId]);
        $body = (string) $response->body();

        $this->assertSame(200, $response->status());
        // The description is written by the service from the order id, so this
        // also proves the row reached the view rather than a placeholder.
        $this->assertStringContainsString('Store order #', $body);
        $this->assertStringContainsString('Retail collected', $body);
        $this->assertStringContainsString('90.00', $body);
    }

    public function test_the_detail_page_is_forbidden_without_the_permission(): void
    {
        $this->signIn($this->plainAdminId);

        $response = $this->controller->show($this->request(), ['clientId' => (string) $this->resellerClientId]);

        $this->assertSame(403, $response->status());
    }

    public function test_the_detail_page_redirects_when_the_client_has_no_store(): void
    {
        $this->signInAsSuperAdmin();
        $storeless = $this->clients->create([
            'email' => 'no-store@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'No',
            'last_name' => 'Store',
        ]);

        $response = $this->controller->show($this->request(), ['clientId' => (string) $storeless]);

        // A reseller with no store has no account, and an explanatory redirect
        // beats an empty page that reads like money went missing.
        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('no store', (string) $this->session->pullFlash('reseller_error'));
    }

    // -------------------------------------------------------------- settings

    public function test_a_negative_holding_period_and_minimum_are_floored_rather_than_stored(): void
    {
        $this->signInAsSuperAdmin();

        $this->controller->saveSettings($this->request([
            'payout_holding_days' => '-5',
            'payout_minimum' => '-100',
        ]));

        // A negative holding period would make the withdrawable figure exceed the
        // balance, and a negative minimum would make an overdrawn account claimable.
        $this->assertSame('0', $this->settings->get('reseller.payout_holding_days'));
        $this->assertSame('0.00', $this->settings->get('reseller.payout_minimum'));
    }

    public function test_changing_the_holding_period_applies_to_receipts_posted_afterwards(): void
    {
        $this->signInAsSuperAdmin();

        // Posted under the 30-day default: its maturity is recorded on the entry
        // as posted + 30 days.
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(80.0, '2026-01-01 09:00:00'));

        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->ledger->accountFor($this->storeId)['withdrawable_base'],
            0.001
        );

        $this->controller->saveSettings($this->request(['payout_holding_days' => '0', 'payout_minimum' => '0']));

        // The existing receipt is NOT released. Each entry stores the date it
        // becomes withdrawable, so a change here governs receipts posted from now
        // on rather than rewriting the terms of money already collected. That is
        // the deliberate direction to fail in: a holding period is a risk control
        // against chargebacks, and holding a receipt longer than the admin
        // intended is recoverable in a way that paying out too early is not.
        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->ledger->accountFor($this->storeId)['withdrawable_base'],
            0.001,
            'an entry posted under the old period must keep the maturity it was given'
        );

        // A receipt posted under the new period is withdrawable immediately, which
        // is what proves the setting actually took effect rather than being ignored.
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(25.0, '2026-01-05 09:00:00'));

        $this->assertEqualsWithDelta(
            25.0,
            (float) $this->ledger->accountFor($this->storeId)['withdrawable_base'],
            0.001
        );
    }

    public function test_payout_settings_are_forbidden_without_the_permission(): void
    {
        $this->signIn($this->plainAdminId);

        $this->controller->saveSettings($this->request(['payout_holding_days' => '1']));

        // Refused before the write, so the seeded 30 days is untouched.
        $this->assertSame('30', $this->settings->get('reseller.payout_holding_days'));
    }

    // ---------------------------------------------------------------- seam

    public function test_the_payout_routes_are_declared_for_the_controller(): void
    {
        // The seam between routes/reseller.php and this controller is a pair of
        // strings no compiler checks: rename a method, or forget the route, and
        // every test above still passes while the page is unreachable.
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/reseller.php');

        foreach (['index', 'show', 'saveSettings'] as $method) {
            $this->assertStringContainsString(
                'AdminResellerAccountsController::class, ' . "'" . $method . "'",
                $routes,
                "No route is declared for AdminResellerAccountsController::{$method}()."
            );
        }
    }

    public function test_the_literal_accounts_path_precedes_the_parameterised_routes(): void
    {
        // '/admin/resellers/accounts' and '/admin/resellers/{clientId}/...' both
        // match a request for the first one if the parameter is not restricted to
        // digits, and the router takes the first match — so order is load-bearing.
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/reseller.php');

        $literal = strpos($routes, "'/admin/resellers/accounts'");
        $parameterised = strpos($routes, "'/admin/resellers/{clientId}/account'");

        $this->assertNotFalse($literal, 'the accounts path is not registered at all');
        $this->assertNotFalse($parameterised, 'the per-store account path is not registered at all');
        $this->assertLessThan(
            $parameterised,
            $literal,
            'the literal accounts path must be registered before the parameterised one, or it can be read as a client id'
        );
    }

    // -------------------------------------------------------------- helpers

    private function signInAsSuperAdmin(): void
    {
        $this->signIn($this->superAdminId);
    }

    private function insertAdmin(string $username, string $email, int $roleId, string $now): int
    {
        return (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$username, $email, password_hash('correct-horse-battery', PASSWORD_ARGON2ID), ucfirst($username), $roleId, $now, $now]
        );
    }

    private function signIn(int $adminId): void
    {
        // Seeded directly rather than through AuthGuard::login(), which calls
        // session_regenerate_id() and warns in CLI where there is no active
        // session. SessionManager reads $_SESSION directly, so this is the same
        // state the guard would have set.
        $_SESSION['admin_id'] = $adminId;
    }

    /**
     * A PAID store order and its invoice, so the accrual has something real to
     * read. Returns the invoice id, which is what the ledger is keyed on.
     */
    private function paidStoreOrder(float $retail, string $at): int
    {
        $customerId = $this->clients->create([
            'email' => 'shopper-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Shop',
            'last_name' => 'Per',
        ]);

        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$customerId, $this->storeId, 'active', $retail, round($retail * 0.8, 2), $at, $at]
        );

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$customerId, $orderId, 'paid', $retail, 0.0, $retail, substr($at, 0, 10), $at, $at]
        );
    }

    /** @param array<string, mixed> $body */
    private function request(array $body = []): Request
    {
        return new Request([], $body, ['REQUEST_METHOD' => 'POST'], []);
    }
}
