<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Request;
use CodeVault\Reseller\AdminResellerBillingController;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerCostBillingJob;
use CodeVault\Reseller\ResellerCostRepository;
use CodeVault\Reseller\ResellerCostService;
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
 * Where the admin actually controls store cost billing: the page, the
 * permission gate, the tunables, and the "bill now" button.
 *
 * The button is the part worth pinning down. It calls the same job the cron
 * calls, so it has to be as safe to press twice as the cron is to run twice —
 * idempotency is a property of the data (each order carries the id of the
 * invoice that billed it), not of who triggered the run.
 */
final class AdminResellerBillingPageTest extends DatabaseTestCase
{
    private AdminResellerBillingController $controller;
    private SessionManager $session;
    private SettingsRepository $settings;
    private ClientRepository $clients;
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

        // Permissions hang off ROLES, not off the admin row: a super-admin role
        // bypasses the check entirely, and a role explicitly granted
        // resellers.manage passes it. Both are covered here, plus a role with
        // neither, so the gate is shown to be keyed on the right permission
        // rather than on "has some role".
        $superRoleId = $roles->create('Super Admin', true, []);
        $supportRoleId = $roles->create('Support', false, []);
        $managerRoleId = $roles->create('Reseller Manager', false, [PermissionRegistry::RESELLERS_MANAGE]);

        $this->superAdminId = $this->insertAdmin('root', 'root@example.test', $superRoleId, $now);
        $this->plainAdminId = $this->insertAdmin('support', 'support@example.test', $supportRoleId, $now);
        $this->managerAdminId = $this->insertAdmin('manager', 'manager@example.test', $managerRoleId, $now);

        $configDir = sys_get_temp_dir() . '/codevault-billing-page-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $this->session = new SessionManager(new Config($configDir));
        $config = new Config($configDir);

        $this->settings = new SettingsRepository($this->db);
        $this->clients = new ClientRepository($this->db);

        // The shared admin layout renders brand_name(), csrf_field() and the nav,
        // all of which resolve from the service locator — so a hand-built
        // controller test has to pin them to THIS database, not the application's.
        $container = new Container();
        $container->instance(SessionManager::class, $this->session);
        $container->instance(Config::class, $config);
        $container->instance(\CodeVault\Database::class, $this->db);
        $container->instance(SettingsRepository::class, $this->settings);
        $container->bind(CsrfToken::class);
        App::setContainer($container);

        $currency = new CurrencyService(new CurrencyRepository($this->db));
        $storeRepo = new ResellerStoreRepository($this->db);
        $stores = new ResellerStoreService($storeRepo, new ResellerStoreLocator($storeRepo, $config), new DomainVerifier());

        $this->resellerClientId = $this->clients->create([
            'email' => 'store-owner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Store',
            'last_name' => 'Owner',
        ]);

        $this->storeId = (int) $stores->openForClient($this->resellerClientId, 'Acme')['store']['id'];

        $costs = new ResellerCostRepository($this->db);
        $service = new ResellerCostService($costs, $storeRepo, $this->clients, $currency);

        $this->controller = new AdminResellerBillingController(
            new AuthGuard($this->session, $admins, new RoleRepository($this->db)),
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->session,
            $this->settings,
            $service,
            $costs,
            new ResellerCostBillingJob(
                $service,
                $costs,
                $currency,
                new TaxCalculator(new TaxRuleRepository($this->db), new VatNumberValidator(), new TaxSettings($this->settings)),
                $this->settings,
                $this->db,
                new HookDispatcher()
            ),
            new ActivityLogger($this->db)
        );
    }

    public function test_the_billing_page_renders_for_a_super_admin(): void
    {
        $this->signInAsSuperAdmin();
        $this->order(30.0, $this->lastMonth());

        $response = $this->controller->index($this->request());

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Store cost billing', (string) $response->body());
        $this->assertStringContainsString('acme', strtolower((string) $response->body()));
        // The accrued figure the admin came to see is on the page.
        $this->assertStringContainsString('30.00', (string) $response->body());
    }

    public function test_the_page_is_forbidden_without_the_resellers_permission(): void
    {
        $this->signIn($this->plainAdminId);

        $response = $this->controller->index($this->request());

        $this->assertSame(403, $response->status());
    }

    public function test_the_page_is_allowed_for_a_role_granted_the_permission(): void
    {
        // Not a super-admin: the gate has to accept the granted permission key
        // on its own, which is what the resellers.manage registry entry is for.
        $this->signIn($this->managerAdminId);

        $response = $this->controller->index($this->request());

        $this->assertSame(200, $response->status());
    }

    public function test_bill_now_raises_the_invoice_and_a_second_press_raises_nothing(): void
    {
        $this->signInAsSuperAdmin();
        $this->order(30.0, $this->lastMonth());

        $first = $this->controller->runNow($this->request());
        $this->assertSame(302, $first->status());
        $this->assertSame(1, $this->invoiceCount());

        $second = $this->controller->runNow($this->request());
        $this->assertSame(302, $second->status());
        $this->assertSame(1, $this->invoiceCount(), 'Pressing "bill now" twice must not bill twice.');

        // And it says so, rather than implying it billed again.
        $this->assertStringContainsString('nothing new', (string) $this->session->pullFlash('reseller_notice'));
    }

    public function test_bill_now_is_forbidden_without_the_permission(): void
    {
        $this->signIn($this->plainAdminId);
        $this->order(30.0, $this->lastMonth());

        $response = $this->controller->runNow($this->request());

        $this->assertSame(403, $response->status());
        $this->assertSame(0, $this->invoiceCount());
    }

    public function test_negative_terms_and_minimums_are_floored_rather_than_stored(): void
    {
        $this->signInAsSuperAdmin();

        $this->controller->saveSettings($this->request([
            'billing_auto' => '1',
            'billing_minimum' => '-25',
            'billing_due_days' => '-3',
        ]));

        // A negative minimum would make every period billable and a negative
        // term would date the invoice in the past.
        $this->assertSame('1', $this->settings->get('reseller.billing_auto'));
        $this->assertSame('0.00', $this->settings->get('reseller.billing_minimum'));
        $this->assertSame('0', $this->settings->get('reseller.billing_due_days'));
    }

    public function test_auto_billing_can_be_turned_off_from_the_form(): void
    {
        $this->signInAsSuperAdmin();

        $this->controller->saveSettings($this->request(['billing_minimum' => '0', 'billing_due_days' => '7']));

        // An absent checkbox posts nothing, and nothing must mean OFF rather
        // than "leave it as it was".
        $this->assertSame('0', $this->settings->get('reseller.billing_auto'));
    }

    public function test_the_billing_routes_are_declared_for_the_controller(): void
    {
        // The seam between routes/reseller.php and this controller is a string
        // pair no compiler checks, so something has to compare them.
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/reseller.php');

        foreach (['index', 'saveSettings', 'runNow'] as $method) {
            $this->assertStringContainsString(
                'AdminResellerBillingController::class, ' . "'" . $method . "'",
                $routes,
                "No route is declared for AdminResellerBillingController::{$method}()."
            );
        }
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

    private function order(float $cost, string $placedAt): int
    {
        $customerId = $this->clients->create([
            'email' => 'shopper-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Shop',
            'last_name' => 'Per',
        ]);

        return (int) $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$customerId, $this->storeId, 'active', $cost, $cost, $placedAt, $placedAt]
        );
    }

    private function lastMonth(): string
    {
        $month = (new DateTimeImmutable('first day of this month 12:00:00'))->modify('-1 month');

        return $month->setDate((int) $month->format('Y'), (int) $month->format('m'), 15)->format('Y-m-d H:i:s');
    }

    private function invoiceCount(): int
    {
        return (int) ($this->db->selectOne(
            'SELECT COUNT(*) AS c FROM invoices WHERE client_id = ?',
            [$this->resellerClientId]
        )['c'] ?? 0);
    }

    /** @param array<string, mixed> $body */
    private function request(array $body = []): Request
    {
        return new Request([], $body, ['REQUEST_METHOD' => 'POST'], []);
    }
}
