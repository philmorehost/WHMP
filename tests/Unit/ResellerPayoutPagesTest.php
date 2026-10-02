<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Request;
use CodeVault\Reseller\AdminResellerPayoutsController;
use CodeVault\Reseller\ClientResellerAccountController;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerLedgerRepository;
use CodeVault\Reseller\ResellerLedgerService;
use CodeVault\Reseller\ResellerPayoutRepository;
use CodeVault\Reseller\ResellerPayoutService;
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
 * The payout pages: the reseller's request, and the admin's answer.
 *
 * ResellerPayoutTest proves the money rules by calling the service. This drives
 * the real controllers, because the rules and the screens can disagree in ways
 * neither side can see alone — a refused request that still posts a row, a
 * controller that takes the amount from the form, an action that is reachable
 * without the permission it needs.
 *
 * THE ASSERTION THAT MATTERS MOST is that the amount is never read from the
 * request. The reseller's form posts nothing but a CSRF token, and the service
 * decides the figure from the account — so a reseller cannot request more than
 * they have by editing a field. That is checked by posting a large amount and
 * asserting it is ignored.
 */
final class ResellerPayoutPagesTest extends DatabaseTestCase
{
    private AdminResellerPayoutsController $admin;
    private ClientResellerAccountController $client;
    private SessionManager $session;
    private SettingsRepository $settings;
    private ClientRepository $clients;
    private ResellerLedgerRepository $ledger;
    private ResellerLedgerService $accounts;
    private ResellerPayoutRepository $payouts;
    private \CodeVault\Reseller\ResellerPayoutDestinationRepository $destinationRepo;
    private ResellerPayoutService $service;
    private ResellerStoreRepository $storeRepo;
    private \CodeVault\Reseller\ResellerStatementService $statements;
    private int $superAdminId;
    private int $plainAdminId;
    private int $resellerClientId;
    private int $customerId;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $admins = new AdminRepository($this->db);
        $roles = new RoleRepository($this->db);

        $superRoleId = $roles->create('Super Admin', true, []);
        $supportRoleId = $roles->create('Support', false, []);

        $this->superAdminId = $this->insertAdmin('root', 'root@example.test', $superRoleId, $now);
        $this->plainAdminId = $this->insertAdmin('support', 'support@example.test', $supportRoleId, $now);

        $configDir = sys_get_temp_dir() . '/codevault-payout-pages-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $config = new Config($configDir);
        $this->session = new SessionManager($config);

        $this->settings = new SettingsRepository($this->db);
        $this->clients = new ClientRepository($this->db);

        // Both layouts render brand_name(), csrf_field() and the nav, all of which
        // resolve from the service locator — so a hand-built controller test has to
        // pin them to THIS database or the page renders against the application's.
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

        $this->customerId = $this->clients->create([
            'email' => 'shopper@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Shopper',
            'last_name' => 'Person',
        ]);

        $this->storeId = (int) $stores->openForClient($this->resellerClientId, 'Acme')['store']['id'];

        $this->ledger = new ResellerLedgerRepository($this->db);
        $this->accounts = new ResellerLedgerService(
            $this->ledger,
            $this->storeRepo,
            $this->clients,
            $currency,
            $this->settings
        );

        $this->payouts = new ResellerPayoutRepository($this->db);
        $this->service = new ResellerPayoutService($this->payouts, $this->ledger, $this->accounts, $currency, new HookDispatcher());

        // A destination must be on file before a payout can be requested, so the
        // fixture records one — exactly what a real reseller does first.
        $this->destinationRepo = new \CodeVault\Reseller\ResellerPayoutDestinationRepository($this->db);
        $this->destinationRepo->save($this->storeId, $this->resellerClientId, [
            'method' => 'bank_transfer',
            'account_name' => 'Acme Ltd',
            'account_number' => '0123456789',
            'bank_name' => 'Test Bank',
            'bank_code' => '000',
            'currency_id' => null,
        ]);

        // The numbered statements are issued by the ADMIN, but the client controller
        // reads them, so it needs the service even though it can never write one.
        $this->statements = new \CodeVault\Reseller\ResellerStatementService(
            new \CodeVault\Reseller\ResellerStatementRepository($this->db),
            $this->accounts,
            $this->settings,
            $currency
        );

        $view = new View(dirname(__DIR__, 2) . '/resources/views');

        $this->admin = new AdminResellerPayoutsController(
            new AuthGuard($this->session, $admins, new RoleRepository($this->db)),
            $view,
            $this->session,
            $this->service,
            $this->storeRepo,
            $this->clients,
            $currency,
            new ActivityLogger($this->db)
        );

        $this->client = new ClientResellerAccountController(
            new ClientAuthGuard($this->session, $this->clients),
            $view,
            $this->session,
            $this->accounts,
            $this->service,
            $this->storeRepo,
            $currency,
            new ActivityLogger($this->db),
            $this->statements,
            $this->destinationRepo
        );
    }

    // ------------------------------------------------------------ admin queue

    public function test_the_queue_shows_a_pending_request_for_a_super_admin(): void
    {
        $this->signInAsSuperAdmin();
        $this->earn(100.0, 60);
        $this->service->request($this->storeId);

        $response = $this->admin->index($this->request());

        $this->assertSame(200, $response->status());
        $body = (string) $response->body();

        $this->assertStringContainsString('Reseller payouts', $body);
        $this->assertStringContainsString('acme', strtolower($body));
        $this->assertStringContainsString('100.00', $body);
    }

    public function test_the_queue_says_nothing_is_waiting_rather_than_showing_an_empty_table(): void
    {
        $this->signInAsSuperAdmin();

        $body = (string) $this->admin->index($this->request())->body();

        $this->assertStringContainsString('Nothing is waiting', $body);
    }

    public function test_the_queue_is_forbidden_without_the_resellers_permission(): void
    {
        $this->signIn($this->plainAdminId);

        $this->assertSame(403, $this->admin->index($this->request())->status());
    }

    // -------------------------------------------------------------- decisions

    public function test_recording_a_payment_stores_the_reference_and_redirects(): void
    {
        $this->signInAsSuperAdmin();
        $this->earn(100.0, 60);
        $id = (int) $this->service->request($this->storeId)['payout']['id'];

        $response = $this->admin->markPaid($this->request(['reference' => 'TRF-88120']), ['payoutId' => (string) $id]);

        $this->assertSame(302, $response->status());
        $paid = $this->payouts->find($id);
        $this->assertSame('paid', (string) $paid['status']);
        $this->assertSame('TRF-88120', (string) $paid['reference']);
        // The admin who pressed the button is recorded, not assumed.
        $this->assertSame($this->superAdminId, (int) $paid['decided_by']);
    }

    public function test_recording_a_payment_without_a_reference_changes_nothing(): void
    {
        $this->signInAsSuperAdmin();
        $this->earn(100.0, 60);
        $id = (int) $this->service->request($this->storeId)['payout']['id'];

        $response = $this->admin->markPaid($this->request(['reference' => '']), ['payoutId' => (string) $id]);

        $this->assertSame(302, $response->status());
        $this->assertSame('pending', (string) $this->payouts->find($id)['status']);
        $this->assertStringContainsString('reference is required', (string) $this->session->pullFlash('reseller_error'));
    }

    public function test_a_payment_cannot_be_recorded_without_the_permission(): void
    {
        $this->signIn($this->plainAdminId);
        $this->earn(100.0, 60);
        $id = (int) $this->service->request($this->storeId)['payout']['id'];

        $response = $this->admin->markPaid($this->request(['reference' => 'TRF-1']), ['payoutId' => (string) $id]);

        $this->assertSame(403, $response->status());
        $this->assertSame('pending', (string) $this->payouts->find($id)['status']);
    }

    public function test_an_admin_rejection_returns_the_funds(): void
    {
        $this->signInAsSuperAdmin();
        $this->earn(100.0, 60);
        $id = (int) $this->service->request($this->storeId)['payout']['id'];

        $response = $this->admin->reject($this->request(['note' => 'Bank details unreadable']), ['payoutId' => (string) $id]);

        $this->assertSame(302, $response->status());
        $this->assertSame('rejected', (string) $this->payouts->find($id)['status']);
        $this->assertEqualsWithDelta(100.0, $this->ledger->balance($this->storeId), 0.001);
    }

    // ------------------------------------------------------ reseller requests

    public function test_the_account_page_offers_a_payout_when_funds_are_ready(): void
    {
        $this->signInAsClient();
        $this->earn(100.0, 60);

        $body = (string) $this->client->index($this->request())->body();

        $this->assertStringContainsString('Getting paid', $body);
        $this->assertStringContainsString('Request a payout', $body);
    }

    public function test_the_account_page_does_not_offer_a_payout_below_the_minimum(): void
    {
        $this->signInAsClient();
        $this->earn(20.0, 60);

        $body = (string) $this->client->index($this->request())->body();

        // Offered the button anyway, the reseller would press it and be refused by
        // a message that should have been the page itself.
        $this->assertStringNotContainsString('Request a payout', $body);
        $this->assertStringContainsString('minimum for a payout', $body);
    }

    public function test_a_reseller_can_request_a_payout(): void
    {
        $this->signInAsClient();
        $this->earn(100.0, 60);

        $response = $this->client->requestPayout($this->request());

        $this->assertSame(302, $response->status());
        $this->assertSame(1, $this->payoutRowCount());

        $open = $this->payouts->openForReseller($this->storeId);
        $this->assertNotNull($open);
        $this->assertEqualsWithDelta(100.0, (float) $open['amount_base'], 0.001);
    }

    public function test_the_amount_comes_from_the_account_and_not_from_the_form(): void
    {
        $this->signInAsClient();
        $this->earn(100.0, 60);

        // A reseller trying to take more than they have. The form posts no amount
        // at all, so this figure must be ignored completely.
        $this->client->requestPayout($this->request(['amount' => '999999', 'amount_base' => '999999']));

        $open = $this->payouts->openForReseller($this->storeId);
        $this->assertEqualsWithDelta(
            100.0,
            (float) $open['amount_base'],
            0.001,
            'the requested amount must come from the account, never from the request'
        );
    }

    public function test_a_reseller_cannot_request_a_payout_with_nothing_matured(): void
    {
        $this->signInAsClient();
        $this->earn(100.0, 0);

        $response = $this->client->requestPayout($this->request());

        $this->assertSame(302, $response->status());
        $this->assertSame(0, $this->payoutRowCount());
        $this->assertStringContainsString('Nothing is available', (string) $this->session->pullFlash('reseller_error'));
    }

    public function test_a_reseller_can_cancel_their_own_pending_request(): void
    {
        $this->signInAsClient();
        $this->earn(100.0, 60);
        $id = (int) $this->service->request($this->storeId)['payout']['id'];

        $response = $this->client->cancelPayout($this->request(), ['payoutId' => (string) $id]);

        $this->assertSame(302, $response->status());
        $this->assertSame('cancelled', (string) $this->payouts->find($id)['status']);
        $this->assertEqualsWithDelta(100.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_a_signed_out_visitor_is_sent_to_the_login(): void
    {
        $_SESSION = [];

        $this->assertSame(302, $this->client->requestPayout($this->request())->status());
        $this->assertSame(302, $this->client->index($this->request())->status());
        $this->assertSame(0, $this->payoutRowCount());
    }

    // ------------------------------------------------- payout destination

    public function test_the_account_page_shows_the_destination_on_file(): void
    {
        $this->signInAsClient();

        $body = (string) $this->client->index($this->request())->body();

        $this->assertStringContainsString('Acme Ltd', $body);
        $this->assertStringContainsString('0123456789', $body);
    }

    public function test_a_payout_cannot_be_requested_without_a_destination(): void
    {
        $this->signInAsClient();
        $this->earn(100.0, 60);
        $this->destinationRepo->forget($this->storeId);

        $response = $this->client->requestPayout($this->request());

        $this->assertSame(302, $response->status());
        $this->assertSame(0, $this->payoutRowCount(), 'A payout with nowhere to send it must not be created.');
    }

    public function test_saving_a_destination_requires_a_name_and_a_number(): void
    {
        $this->signInAsClient();
        $this->destinationRepo->forget($this->storeId);

        $this->client->savePayoutMethod($this->request(['account_name' => 'Acme Ltd', 'account_number' => '']));

        $this->assertNull($this->destinationRepo->find($this->storeId));
    }

    public function test_saving_a_destination_records_it_and_clears_a_previous_verification(): void
    {
        $this->signInAsClient();
        $this->destinationRepo->markVerified($this->storeId, null);
        $this->assertNotNull($this->destinationRepo->find($this->storeId)['verified_at']);

        $this->client->savePayoutMethod($this->request([
            'account_name' => 'Acme Ltd',
            'account_number' => '9999999999',
            'bank_name' => 'Other Bank',
        ]));

        $destination = $this->destinationRepo->find($this->storeId);
        $this->assertSame('9999999999', (string) $destination['account_number']);
        $this->assertNull($destination['verified_at'], 'Changing the account number must un-verify it.');
    }

    public function test_a_request_freezes_the_destination_so_a_later_edit_cannot_restate_it(): void
    {
        $this->signInAsClient();
        $this->earn(100.0, 60);
        $this->client->requestPayout($this->request());

        $payoutId = (int) $this->payouts->openForReseller($this->storeId)['id'];

        // The reseller moves bank AFTER asking to be paid.
        $this->destinationRepo->save($this->storeId, $this->resellerClientId, [
            'method' => 'bank_transfer',
            'account_name' => 'Acme Ltd',
            'account_number' => '5555555555',
            'bank_name' => 'New Bank',
            'bank_code' => '111',
            'currency_id' => null,
        ]);

        $snapshot = (string) $this->payouts->find($payoutId)['destination_snapshot'];

        $this->assertStringContainsString('0123456789', $snapshot, 'The payout must keep where the money was actually sent.');
        $this->assertStringNotContainsString('5555555555', $snapshot);
    }

    public function test_the_admin_queue_shows_where_to_send_the_money(): void
    {
        $this->signInAsSuperAdmin();
        $this->earn(100.0, 60);
        $this->service->request($this->storeId, null, $this->destinationRepo->find($this->storeId));

        $body = (string) $this->admin->index($this->request())->body();

        $this->assertStringContainsString('0123456789', $body);
    }

    // ------------------------------------------------------------------ seam

    public function test_the_payout_routes_are_declared_for_their_controllers(): void
    {
        // A hook name and a class name joined by a string, again: no compiler
        // checks that a route exists for a method, or that it points at the right
        // controller.
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/reseller.php');

        foreach ([
            'AdminResellerPayoutsController' => ['index', 'markPaid', 'reject'],
            'ClientResellerAccountController' => ['requestPayout', 'cancelPayout', 'savePayoutMethod', 'removePayoutMethod'],
        ] as $class => $methods) {
            foreach ($methods as $method) {
                $this->assertStringContainsString(
                    $class . '::class, ' . "'" . $method . "'",
                    $routes,
                    "No route is declared for {$class}::{$method}()."
                );
            }
        }
    }

    public function test_the_literal_payouts_path_precedes_a_client_id_shaped_one(): void
    {
        // '/admin/resellers/payouts' and '/admin/resellers/{clientId}/...' can both
        // match one request, and the router takes the first match — so this order
        // is load-bearing, not cosmetic.
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/reseller.php');

        $literal = strpos($routes, "'/admin/resellers/payouts'");
        $parameterised = strpos($routes, "'/admin/resellers/{clientId}/account'");

        $this->assertNotFalse($literal, 'the payout queue path is not registered at all');
        $this->assertNotFalse($parameterised, 'the per-store account path is not registered at all');
        $this->assertLessThan(
            $parameterised,
            $literal,
            'the literal payouts path must be registered before the parameterised one'
        );
    }

    // -------------------------------------------------------------- helpers

    private function signInAsSuperAdmin(): void
    {
        $this->signIn($this->superAdminId);
    }

    private function signIn(int $adminId): void
    {
        // Seeded directly rather than through AuthGuard::login(), which calls
        // session_regenerate_id() and warns in CLI where there is no active session.
        $_SESSION['admin_id'] = $adminId;
    }

    // --------------------------------------------------- numbered statements

    public function test_the_reseller_sees_their_own_issued_statements(): void
    {
        $this->signInAsClient();
        $issued = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $response = $this->client->statements($this->request());
        $body = (string) $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString((string) $issued['number'], $body);
        $this->assertStringContainsString('/client/reseller/statements/' . (int) $issued['id'], $body);
    }

    public function test_the_reseller_sees_their_own_statement_document(): void
    {
        $this->signInAsClient();
        $issued = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $body = (string) $this->client->showStatement(
            $this->request(),
            ['statementId' => (string) $issued['id']]
        )->body();

        $this->assertStringContainsString((string) $issued['number'], $body);
        $this->assertStringContainsString('2026-08-01', $body);
    }

    public function test_a_reseller_cannot_open_another_stores_statement(): void
    {
        // A REAL statement belonging to a DIFFERENT store. The id is genuine and the
        // signed-in reseller is genuine — only the pairing is wrong, which is the
        // case an id-only check misses.
        $otherClientId = $this->clients->create([
            'email' => 'other-store-owner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Other',
            'last_name' => 'Owner',
        ]);
        $now = date('Y-m-d H:i:s');
        $otherStoreId = (int) $this->db->insert(
            'INSERT INTO resellers (client_id, slug, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$otherClientId, 'other-store-' . uniqid(), 'active', $now, $now]
        );
        $other = $this->statements->issue($otherStoreId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->signInAsClient();
        $response = $this->client->showStatement($this->request(), ['statementId' => (string) $other['id']]);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString(
            'not available',
            (string) $this->session->pullFlash('reseller_error')
        );
    }

    private function signInAsClient(): void
    {
        $_SESSION['client_id'] = $this->resellerClientId;
    }

    private function insertAdmin(string $username, string $email, int $roleId, string $now): int
    {
        return (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$username, $email, password_hash('correct-horse-battery', PASSWORD_ARGON2ID), ucfirst($username), $roleId, $now, $now]
        );
    }

    /** Credit a matured (or not) store receipt by making one of its orders paid. */
    private function earn(float $retail, int $daysAgo): void
    {
        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $this->storeId, 'active', $retail, round($retail * 0.8, 2), '2026-01-01 09:00:00', '2026-01-01 09:00:00']
        );

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $orderId, 'paid', $retail, 0.0, $retail, '2026-01-01', '2026-01-01 09:00:00', '2026-01-01 09:00:00']
        );

        $this->accounts->accrueStoreReceipt(
            $invoiceId,
            $daysAgo === 0
                ? (new DateTimeImmutable())->format('Y-m-d H:i:s')
                : (new DateTimeImmutable('-' . $daysAgo . ' days'))->format('Y-m-d H:i:s')
        );
    }

    private function payoutRowCount(): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM reseller_payouts')['c'] ?? 0);
    }

    /** @param array<string, mixed> $body */
    private function request(array $body = []): Request
    {
        return new Request([], $body, ['REQUEST_METHOD' => 'POST'], []);
    }
}
