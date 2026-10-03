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
use CodeVault\Reseller\ResellerStatementRepository;
use CodeVault\Reseller\ResellerStatementService;
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
    private ResellerStatementService $statements;
    private ResellerStoreRepository $storeRepo;
    private int $superAdminId;
    private int $plainAdminId;
    private int $managerAdminId;
    private int $statementIssuerAdminId;
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
        $statementIssuerRoleId = $roles->create('Statement Issuer', false, [
            PermissionRegistry::RESELLERS_MANAGE,
            PermissionRegistry::RESELLER_STATEMENTS_ISSUE,
        ]);

        $this->superAdminId = $this->insertAdmin('root', 'root@example.test', $superRoleId, $now);
        $this->plainAdminId = $this->insertAdmin('support', 'support@example.test', $supportRoleId, $now);
        $this->managerAdminId = $this->insertAdmin('manager', 'manager@example.test', $managerRoleId, $now);
        $this->statementIssuerAdminId = $this->insertAdmin('issuer', 'issuer@example.test', $statementIssuerRoleId, $now);

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

        $this->statements = new ResellerStatementService(
            new ResellerStatementRepository($this->db),
            $this->ledger,
            $this->settings,
            $currency
        );

        $this->controller = new AdminResellerAccountsController(
            new AuthGuard($this->session, $admins, new RoleRepository($this->db)),
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->session,
            $this->settings,
            $this->ledger,
            $this->storeRepo,
            $currency,
            new ActivityLogger($this->db),
            $this->statements
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

    public function test_the_page_exposes_a_currency_specific_minimum_for_each_configured_currency(): void
    {
        $this->signInAsSuperAdmin();
        (new CurrencyRepository($this->db))->create('NGN', '₦', 1490.0);

        $body = (string) $this->controller->index($this->request())->body();

        $this->assertStringContainsString('payout_minimums[USD]', $body);
        $this->assertStringContainsString('payout_minimums[NGN]', $body);
        $this->assertStringContainsString('Current base equivalent', $body);
    }

    public function test_saving_currency_minimums_clamps_negative_values_and_ignores_unknown_codes(): void
    {
        $this->signInAsSuperAdmin();
        (new CurrencyRepository($this->db))->create('NGN', '₦', 1490.0);

        $this->controller->saveSettings($this->request([
            'payout_holding_days' => '30',
            'payout_minimums' => [
                'USD' => '25.75',
                'NGN' => '-10',
                'ZZZ' => '999999',
            ],
        ]));

        $minimums = json_decode((string) $this->settings->get('reseller.payout_minimums', '{}'), true);
        $this->assertIsArray($minimums);
        $this->assertSame('25.75', $minimums['USD']);
        $this->assertSame('0.00', $minimums['NGN']);
        $this->assertArrayNotHasKey('ZZZ', $minimums);
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

        foreach (['index', 'show', 'saveSettings', 'export', 'statement', 'issueStatement', 'showStatement'] as $method) {
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

        // The export path is more specific than the per-store account path but
        // shares its prefix, so it has to be registered first as well — otherwise
        // a request for the export can be swallowed by the account route.
        $export = strpos($routes, "'/admin/resellers/{clientId}/account/export'");

        $this->assertNotFalse($export, 'the export path is not registered at all');
        $this->assertLessThan(
            $parameterised,
            $export,
            'the export path must be registered before the per-store account path'
        );
    }

    // --------------------------------------------------------------- export

    public function test_the_export_is_a_csv_of_the_ledger_with_plain_ids_and_no_totals(): void
    {
        $this->signInAsSuperAdmin();

        $invoiceId = $this->paidStoreOrder(90.0, '2026-01-01 09:00:00');
        $this->ledger->accrueStoreReceipt($invoiceId, '2026-01-01 09:00:00');

        $response = $this->controller->export($this->request(), ['clientId' => (string) $this->resellerClientId]);
        $body = (string) $response->body();
        $headers = $response->headers();

        $this->assertSame(200, $response->status());
        $this->assertSame('text/csv; charset=utf-8', $headers['Content-Type']);
        $this->assertStringContainsString('attachment;', $headers['Content-Disposition']);
        $this->assertStringContainsString(
            'reseller-ledger-' . $this->resellerClientId,
            $headers['Content-Disposition'],
            'the filename must identify which store the file belongs to'
        );

        $rows = $this->csvRows($body);

        // The plan's column set, in the plan's order, with the amount named for
        // its unit because the file has no screen to explain it.
        $this->assertSame(
            ['created_at', 'kind', 'amount_base', 'withdrawable_at', 'order_id', 'invoice_id', 'payout_id', 'description'],
            $rows[0]
        );
        $this->assertCount(2, $rows, 'one header plus exactly one entry');

        // By POSITION, not by substring: a shifted column would silently mis-pair
        // every value with every other, which is worse than losing a row.
        $this->assertSame('store_receipt', $rows[1][1]);
        $this->assertEqualsWithDelta(90.0, (float) $rows[1][2], 0.0001, 'the amount must be the base figure, unconverted');
        $this->assertNotSame('', $rows[1][3], 'a receipt must carry the date it becomes withdrawable');
        $this->assertSame((string) $invoiceId, $rows[1][5]);
        $this->assertSame('', $rows[1][6], 'a receipt has no payout yet');

        // The two things the export must do that the screens do not: the ids are
        // plain values (nothing to click, nothing to host) and there is no total
        // to reconcile against — the ledger is the authority, and a spreadsheet
        // can sum a column but cannot un-sum a wrong one.
        $this->assertStringNotContainsString('href', $body);
        $this->assertStringNotContainsString('Total', $body);
        $this->assertStringNotContainsString('Balance', $body);
    }

    public function test_the_export_lists_the_entries_in_the_order_the_money_moved(): void
    {
        $this->signInAsSuperAdmin();

        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(10.0, '2026-02-01 09:00:00'), '2026-02-01 09:00:00');
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(20.0, '2026-03-01 09:00:00'), '2026-03-01 09:00:00');

        $rows = $this->csvRows((string) $this->controller
            ->export($this->request(), ['clientId' => (string) $this->resellerClientId])
            ->body());

        // OLDEST FIRST — the opposite of the page, which leads with the newest
        // entry. A file a program reconciles against other records has to read in
        // the order the money actually moved. ISO timestamps sort lexically, so
        // the string comparison is a real ordering check and not a trick.
        $this->assertEqualsWithDelta(10.0, (float) $rows[1][2], 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $rows[2][2], 0.0001);
        $this->assertLessThan($rows[2][0], $rows[1][0], 'the export must be oldest-first');
    }

    public function test_the_export_is_not_capped_at_the_page_size(): void
    {
        $this->signInAsSuperAdmin();

        // The account PAGE shows at most 200 entries. An export that inherited
        // that cap would silently drop rows from a file someone reconciles money
        // against, so the repository has a separate unlimited query — and this
        // proves the export uses it rather than trusting the comment on it.
        $repo = new ResellerLedgerRepository($this->db);

        for ($i = 1; $i <= 205; $i++) {
            $repo->append([
                'reseller_id' => $this->storeId,
                'client_id' => $this->resellerClientId,
                'kind' => 'adjustment',
                'amount' => 1.0,
                'withdrawable_at' => null,
                'order_id' => null,
                'invoice_id' => null,
                'payout_id' => null,
                'description' => 'bulk ' . $i,
                'admin_id' => null,
                'created_at' => sprintf('2026-01-%02d 00:00:00', (($i - 1) % 28) + 1),
            ]);
        }

        $rows = $this->csvRows((string) $this->controller
            ->export($this->request(), ['clientId' => (string) $this->resellerClientId])
            ->body());

        // Header + 205. The page would have shown only 200 of them.
        $this->assertCount(206, $rows, 'the export must not inherit the 200-row page cap');
    }

    public function test_the_export_is_forbidden_without_the_permission(): void
    {
        $this->signIn($this->plainAdminId);

        $response = $this->controller->export($this->request(), ['clientId' => (string) $this->resellerClientId]);

        // Gated on the same permission as show(): an export is a read with a
        // different content type, so whoever may read the account may copy it —
        // and whoever may not, may not.
        $this->assertSame(403, $response->status());
    }

    public function test_the_export_redirects_when_the_client_has_no_store(): void
    {
        $this->signInAsSuperAdmin();
        $storeless = $this->clients->create([
            'email' => 'no-store-export@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'No',
            'last_name' => 'Store',
        ]);

        $response = $this->controller->export($this->request(), ['clientId' => (string) $storeless]);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('no store', (string) $this->session->pullFlash('reseller_error'));
    }

    // ------------------------------------------------------------ statement

    public function test_the_statement_carries_the_balance_in_and_out_across_the_period(): void
    {
        $this->signInAsSuperAdmin();

        // Before, inside, and AFTER the period. The third is the one that
        // matters: a statement that leaked a later month would still look
        // plausible, because every figure on it would be a real figure.
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(100.0, '2026-07-20 10:00:00'), '2026-07-20 10:00:00');
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(50.0, '2026-08-15 10:00:00'), '2026-08-15 10:00:00');
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(999.0, '2026-09-05 10:00:00'), '2026-09-05 10:00:00');

        $statement = $this->ledger->statementFor($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->assertNotNull($statement);
        $this->assertEqualsWithDelta(100.0, (float) $statement['opening_base'], 0.001, 'only the July receipt is carried in');
        $this->assertEqualsWithDelta(50.0, (float) $statement['credits_base'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $statement['debits_base'], 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $statement['closing_base'], 0.001);
        $this->assertSame(1, $statement['entry_count'], 'only the August entry belongs in an August statement');
    }

    public function test_the_withdrawable_figure_is_taken_at_the_period_end_not_at_today(): void
    {
        $this->signInAsSuperAdmin();

        // 1 June matures on 1 July, so it IS withdrawable by 31 August.
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(60.0, '2026-06-01 09:00:00'), '2026-06-01 09:00:00');
        // 15 August matures on 14 September: owed in August, not available in it.
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(40.0, '2026-08-15 09:00:00'), '2026-08-15 09:00:00');

        $statement = $this->ledger->statementFor($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        // 100 owed, 60 available — two figures neither of which can be produced
        // by the other, so this cannot pass by printing one number twice.
        $this->assertEqualsWithDelta(100.0, (float) $statement['closing_base'], 0.001);
        $this->assertEqualsWithDelta(
            60.0,
            (float) $statement['withdrawable_base'],
            0.001,
            'the August receipt had not matured by 31 August and must not count as available in it'
        );
    }

    public function test_the_statement_page_prints_the_period_it_was_asked_for(): void
    {
        $this->signInAsSuperAdmin();
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(100.0, '2026-07-20 10:00:00'), '2026-07-20 10:00:00');
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(50.0, '2026-08-15 10:00:00'), '2026-08-15 10:00:00');

        $response = $this->controller->statement(
            $this->queryRequest(['from' => '2026-08-01', 'to' => '2026-08-31']),
            ['clientId' => (string) $this->resellerClientId]
        );
        $body = (string) $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('2026-08-01', $body);
        $this->assertStringContainsString('2026-08-31', $body);
        // Opening in, the period's credit, and the sum a reader can check.
        $this->assertStringContainsString('100.00', $body);
        $this->assertStringContainsString('50.00', $body);
        $this->assertStringContainsString('150.00', $body);
    }

    public function test_the_period_includes_its_final_day_to_the_last_second(): void
    {
        $this->signInAsSuperAdmin();

        // 23:30 on the 31st, driven through the CONTROLLER with a plain date, so
        // this tests the period normalisation and not just the query. A period
        // end left at midnight on the last day would silently drop every entry
        // posted that day — which is the day a monthly billing run writes them.
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(42.0, '2026-08-31 23:30:00'), '2026-08-31 23:30:00');

        $body = (string) $this->controller->statement(
            $this->queryRequest(['from' => '2026-08-01', 'to' => '2026-08-31']),
            ['clientId' => (string) $this->resellerClientId]
        )->body();

        $this->assertStringContainsString(
            '42.00',
            $body,
            'an entry posted during the final day of the period must be in the period'
        );
    }

    public function test_the_statement_defaults_to_the_current_month(): void
    {
        $this->signInAsSuperAdmin();

        $body = (string) $this->controller
            ->statement($this->request(), ['clientId' => (string) $this->resellerClientId])
            ->body();

        $this->assertStringContainsString('value="' . date('Y-m-01') . '"', $body);
        $this->assertStringContainsString('value="' . date('Y-m-t') . '"', $body);
    }

    public function test_a_nonsense_period_falls_back_to_the_current_month_rather_than_failing(): void
    {
        $this->signInAsSuperAdmin();

        $nonsense = [
            'a period that runs backwards' => ['from' => '2026-09-01', 'to' => '2026-08-01'],
            'a date that does not exist' => ['from' => '2026-02-31', 'to' => '2026-08-01'],
            'not dates at all' => ['from' => 'not-a-date', 'to' => 'yesterday'],
        ];

        foreach ($nonsense as $case => $query) {
            $response = $this->controller->statement(
                $this->queryRequest($query),
                ['clientId' => (string) $this->resellerClientId]
            );
            $body = (string) $response->body();

            $this->assertSame(200, $response->status(), $case . ': a bad period must not break the page');
            $this->assertStringContainsString(
                'value="' . date('Y-m-01') . '"',
                $body,
                $case . ': the current month should be shown instead'
            );
        }
    }

    public function test_the_statement_is_forbidden_without_the_permission(): void
    {
        $this->signIn($this->plainAdminId);

        $response = $this->controller->statement($this->request(), ['clientId' => (string) $this->resellerClientId]);

        $this->assertSame(403, $response->status());
    }

    public function test_the_statement_redirects_when_the_client_has_no_store(): void
    {
        $this->signInAsSuperAdmin();
        $storeless = $this->clients->create([
            'email' => 'no-store-statement@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'No',
            'last_name' => 'Store',
        ]);

        $response = $this->controller->statement($this->request(), ['clientId' => (string) $storeless]);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('no store', (string) $this->session->pullFlash('reseller_error'));
    }

    // ------------------------------------------------- issued statements

    public function test_read_permission_alone_cannot_issue_an_immutable_statement(): void
    {
        $this->signIn($this->managerAdminId);

        $body = (string) $this->controller->statement(
            $this->request(),
            ['clientId' => (string) $this->resellerClientId]
        )->body();
        $this->assertStringNotContainsString('Issue for', $body);

        $response = $this->controller->issueStatement(
            $this->request(['from' => '2026-08-01', 'to' => '2026-08-31']),
            ['clientId' => (string) $this->resellerClientId]
        );

        $this->assertSame(403, $response->status());
        $this->assertCount(0, $this->statements->listing($this->storeId));
    }

    public function test_statement_issuer_permission_shows_and_allows_the_issue_action(): void
    {
        $this->signIn($this->statementIssuerAdminId);

        $body = (string) $this->controller->statement(
            $this->request(),
            ['clientId' => (string) $this->resellerClientId]
        )->body();
        $this->assertStringContainsString('Issue for', $body);

        $response = $this->controller->issueStatement(
            $this->request(['from' => '2026-08-01', 'to' => '2026-08-31']),
            ['clientId' => (string) $this->resellerClientId]
        );
        $this->assertSame(302, $response->status());
        $this->assertCount(1, $this->statements->listing($this->storeId));
    }

    public function test_issuing_a_statement_issues_the_period_that_was_posted(): void
    {
        $this->signInAsSuperAdmin();
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(80.0, '2026-08-10 09:00:00'), '2026-08-10 09:00:00');

        // POSTED, as the issue button sends it. The period has to be read from the
        // body: `query()` and `input()` are separate sources, so a controller that
        // read only the query string would quietly issue the CURRENT month instead,
        // which looks exactly like it worked.
        $response = $this->controller->issueStatement(
            $this->request(['from' => '2026-08-01', 'to' => '2026-08-31']),
            ['clientId' => (string) $this->resellerClientId]
        );

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('/statements/', (string) $response->headers()['Location']);

        $issued = $this->statements->listing($this->storeId);
        $this->assertCount(1, $issued);
        $this->assertSame('2026-08-01 00:00:00', $issued[0]['period_from'], 'the posted period must be the issued period');
        $this->assertSame('2026-08-31 23:59:59', $issued[0]['period_to']);
        $this->assertSame('STMT-2026-0001', (string) $issued[0]['number']);
    }

    public function test_issuing_the_same_period_twice_keeps_one_document_and_says_so(): void
    {
        $this->signInAsSuperAdmin();

        $this->controller->issueStatement(
            $this->request(['from' => '2026-08-01', 'to' => '2026-08-31']),
            ['clientId' => (string) $this->resellerClientId]
        );

        // Cleared before the second call. A one-shot flash cannot distinguish "set
        // earlier in this request" from "never rendered", so leaving it queued would
        // silently discard the second message — the exact trap worth avoiding in a
        // test that is about the second message.
        $this->session->pullFlash('reseller_notice');

        $this->controller->issueStatement(
            $this->request(['from' => '2026-08-01', 'to' => '2026-08-31']),
            ['clientId' => (string) $this->resellerClientId]
        );

        $this->assertStringContainsString(
            'already issued',
            strtolower((string) $this->session->pullFlash('reseller_notice'))
        );
        $this->assertCount(1, $this->statements->listing($this->storeId), 'a repeat must not mint a second number');
    }

    public function test_the_issued_list_appears_on_the_statement_page(): void
    {
        $this->signInAsSuperAdmin();
        $issued = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $body = (string) $this->controller
            ->statement($this->request(), ['clientId' => (string) $this->resellerClientId])
            ->body();

        $this->assertStringContainsString((string) $issued['number'], $body);
        $this->assertStringContainsString('/statements/' . (int) $issued['id'], $body);
    }

    public function test_the_issued_document_renders_the_frozen_figures(): void
    {
        $this->signInAsSuperAdmin();
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(80.0, '2026-08-10 09:00:00'), '2026-08-10 09:00:00');
        $issued = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $body = (string) $this->controller->showStatement($this->request(), [
            'clientId' => (string) $this->resellerClientId,
            'statementId' => (string) $issued['id'],
        ])->body();

        $this->assertStringContainsString((string) $issued['number'], $body);
        $this->assertStringContainsString('Account statement', $body);
        $this->assertStringContainsString('data-print-document', $body);
        $this->assertStringContainsString('not a customer sales invoice', $body);
        $this->assertStringContainsString('80.00', $body);
        $this->assertStringContainsString('2026-08-01', $body);
    }

    public function test_a_statement_cannot_be_opened_through_another_stores_url(): void
    {
        $this->signInAsSuperAdmin();
        $issued = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        // A second store, so there is a real "someone else's URL" to try. The pair
        // (real statement id, real admin, wrong store) is the case an id-only check
        // misses entirely.
        //
        // The store MUST exist: without one the controller bails out on "no store"
        // before it ever reaches the ownership check, and the test would then be
        // asserting a guard that never ran — which is exactly what the first version
        // of this test did.
        $otherClientId = $this->clients->create([
            'email' => 'statement-idor@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Other',
            'last_name' => 'Store',
        ]);

        $stores = new ResellerStoreService(
            $this->storeRepo,
            new ResellerStoreLocator($this->storeRepo, new Config(sys_get_temp_dir())),
            new DomainVerifier()
        );
        $otherStoreId = (int) $stores->openForClient($otherClientId, 'Idor Target')['store']['id'];

        $this->assertNotSame($this->storeId, $otherStoreId, 'the fixture needs two distinct stores');
        $this->assertSame(
            $this->storeId,
            (int) $issued['reseller_id'],
            'the statement must belong to the FIRST store, or there is nothing to protect'
        );

        $response = $this->controller->showStatement($this->request(), [
            'clientId' => (string) $otherClientId,
            'statementId' => (string) $issued['id'],
        ]);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString(
            'does not belong',
            (string) $this->session->pullFlash('reseller_error')
        );
    }

    // -------------------------------------------------------------- helpers

    /**
     * Parse a CSV body into rows with str_getcsv rather than exploding on
     * commas: a description can legitimately contain one, and splitting naively
     * would shift every later column by one.
     *
     * @return array<int, array<int, string|null>>
     */
    private function csvRows(string $body): array
    {
        $rows = [];

        foreach (explode("\n", trim($body)) as $line) {
            if ($line !== '') {
                $rows[] = str_getcsv($line);
            }
        }

        return $rows;
    }

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

    /**
     * A GET request carrying a query string, which is how the statement takes its
     * period. Kept separate from request() so neither can be mistaken for the
     * other: a period passed as a POST body would look like it worked and read
     * the default month instead.
     *
     * @param array<string, string> $query
     */
    private function queryRequest(array $query): Request
    {
        return new Request($query, [], ['REQUEST_METHOD' => 'GET'], []);
    }
}
