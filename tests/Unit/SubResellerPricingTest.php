<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Reseller\AdminResellerController;
use CodeVault\Reseller\ResellerEligibility;
use CodeVault\Reseller\ResellerLedgerService;
use CodeVault\Reseller\ResellerPricing;
use CodeVault\Reseller\ResellerRetailPriceRepository;
use CodeVault\Reseller\ResellerRetailPricing;
use CodeVault\Reseller\ResellerSettings;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\ScriptedDatabase;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * Two-tier resale.
 *
 *  - A customer of a reseller who turns reseller (a SUB-RESELLER) buys at that
 *    reseller's retail prices, so the first reseller keeps its profit on them.
 *  - A sub-reseller's own customers cannot resell: the chain stops at two tiers.
 *
 * The chain is three stores here: store 1 (owned by platform customer 100, markup
 * 25%), store 2 (owned by client 200, a customer of store 1, markup 10%) and the
 * discounts are 20% on services, 10% on domains.
 */
final class SubResellerPricingTest extends TestCase
{
    private ScriptedDatabase $db;
    private ResellerRetailPricing $retail;

    /** @var array<int, array<string, mixed>> */
    private array $stores = [
        1 => ['id' => 1, 'client_id' => 100, 'slug' => 'alpha', 'brand_name' => 'Alpha', 'markup_percent' => 25],
        2 => ['id' => 2, 'client_id' => 200, 'slug' => 'beta', 'brand_name' => 'Beta', 'markup_percent' => 10],
    ];

    /** @var array<int, int|null> client id => the store they registered on */
    private array $clientStore = [100 => null, 200 => 1];

    /** @var array<int, array<string, float>> per-store product overrides */
    private array $overrides = [];

    protected function setUp(): void
    {
        $this->db = new ScriptedDatabase();
        $this->db->on('/FROM settings/', static fn (array $b): array => [[
            'value' => ($b[0] ?? '') === ResellerSettings::KEY_DOMAINS ? '10' : '20',
        ]]);
        $this->db->on('/FROM clients c JOIN resellers u/', function (array $b): array {
            [$ownerId, $storeId] = $b;
            $upId = $this->clientStore[(int) $ownerId] ?? null;

            return $upId !== null && $upId !== (int) $storeId ? [$this->stores[$upId]] : [];
        });
        $this->db->on('/FROM reseller_prices WHERE reseller_id/', function (array $b): array {
            $rows = [];
            foreach ($this->overrides[(int) $b[0]] ?? [] as $key => $price) {
                [$product, $cycle] = explode(':', $key);
                $rows[] = ['product_id' => (int) $product, 'billing_cycle' => $cycle, 'price' => $price];
            }

            return $rows;
        });

        $settings = new ResellerSettings(new SettingsRepository($this->db));
        $this->retail = new ResellerRetailPricing(
            new ResellerPricing($this->db, $settings),
            new ResellerRetailPriceRepository($this->db),
            new ResellerStoreRepository($this->db)
        );
    }

    // ---------------------------------------------------------------- pricing ---

    public function test_a_first_tier_store_prices_exactly_as_before(): void
    {
        $quote = $this->retail->quoteProduct(100.0, $this->stores[1], 5, 'monthly');

        $this->assertSame(100.0, $quote['list']);
        $this->assertSame(80.0, $quote['cost'], 'list less the 20% service discount');
        $this->assertSame(125.0, $quote['retail'], 'list plus the 25% markup');
        $this->assertNull($quote['upline_id']);
        $this->assertNull($quote['upline_cost']);
    }

    public function test_a_sub_reseller_buys_at_its_uplines_retail_price(): void
    {
        $quote = $this->retail->quoteProduct(100.0, $this->stores[2], 5, 'monthly');

        $this->assertSame(125.0, $quote['cost'], 'the sub-reseller pays what the upline\'s customers pay');
        $this->assertSame(137.5, $quote['retail'], 'its own 10% markup goes on top of that');
        $this->assertSame(12.5, $quote['margin']);
        $this->assertSame(0.0, $quote['discount_percent'], 'our reseller discount is not theirs');
        $this->assertSame(1, $quote['upline_id']);
        $this->assertSame(80.0, $quote['upline_cost'], 'the upline still owes only its own cost');
        $this->assertSame(100.0, $quote['catalogue_list']);
    }

    public function test_the_upline_keeps_its_profit_on_the_sale(): void
    {
        $quote = $this->retail->quoteProduct(100.0, $this->stores[2], 5, 'monthly');

        // What the ledger credits the upline: the sub-reseller's cost less the upline's own.
        $margin = ResellerLedgerService::uplineMarginOf([
            'upline_reseller_id' => $quote['upline_id'],
            'cost_total' => $quote['cost'],
            'upline_cost_total' => $quote['upline_cost'],
        ]);

        $this->assertSame(45.0, $margin, 'exactly the profit the upline makes selling to its own customer');
    }

    public function test_the_uplines_hand_set_price_is_what_the_sub_reseller_pays(): void
    {
        $this->overrides[1] = ['5:monthly' => 150.0];

        $quote = $this->retail->quoteProduct(100.0, $this->stores[2], 5, 'monthly');

        $this->assertSame(150.0, $quote['cost']);
        $this->assertSame(165.0, $quote['retail']);
    }

    public function test_a_sub_reseller_cannot_override_below_what_it_pays(): void
    {
        $quote = $this->retail->quoteProduct(100.0, $this->stores[2], 5, 'monthly');

        $this->assertNotNull(
            $this->retail->overrideProblem(100.0, $quote['cost'], 'Plan'),
            'below the upline\'s price — our list is no longer the floor'
        );
        $this->assertNull($this->retail->overrideProblem(130.0, $quote['cost'], 'Plan'));
    }

    public function test_domains_and_fees_follow_the_same_chain(): void
    {
        $domain = $this->retail->quoteDomain(20.0, $this->stores[2], 'com', 'register');
        $this->assertSame(25.0, $domain['cost'], 'upline retail: 20 + 25%');
        $this->assertSame(27.5, $domain['retail']);
        $this->assertSame(18.0, $domain['upline_cost'], 'the upline pays list less the 10% domain discount');

        $this->assertSame(12.5, $this->retail->storeCostFor(10.0, 'service', $this->stores[2]), 'a setup fee costs the sub-reseller the upline\'s price');
        $this->assertSame(8.0, $this->retail->storeCostFor(10.0, 'service', $this->stores[1]));
        $this->assertSame(8.0, $this->retail->uplineCostFor(10.0, 'service', $this->stores[2]));
        $this->assertNull($this->retail->uplineCostFor(10.0, 'service', $this->stores[1]));
    }

    public function test_a_cycle_between_two_stores_does_not_recurse_forever(): void
    {
        // Each owner a customer of the other's store: not creatable now, but data can be old.
        $this->clientStore = [100 => 2, 200 => 1];

        $quote = $this->retail->quoteProduct(100.0, $this->stores[1], 5, 'monthly');

        $this->assertGreaterThan(0.0, $quote['retail']);
    }

    public function test_no_upline_margin_without_an_upline_or_a_profit(): void
    {
        $this->assertNull(ResellerLedgerService::uplineMarginOf(['upline_reseller_id' => null, 'cost_total' => 10, 'upline_cost_total' => 5]));
        $this->assertNull(ResellerLedgerService::uplineMarginOf(['upline_reseller_id' => 1, 'cost_total' => 10, 'upline_cost_total' => null]));
        $this->assertNull(ResellerLedgerService::uplineMarginOf(['upline_reseller_id' => 1, 'cost_total' => 10, 'upline_cost_total' => 10]));
        $this->assertSame(2.5, ResellerLedgerService::uplineMarginOf(['upline_reseller_id' => 1, 'cost_total' => '12.50', 'upline_cost_total' => '10.00']));
    }

    // ------------------------------------------------------------- eligibility ---

    public function test_only_two_tiers_may_resell(): void
    {
        $this->assertSame(2, ResellerEligibility::tierFromOwnerStore(null), 'the store\'s owner is a platform customer');
        $this->assertNull(ResellerEligibility::tierFromOwnerStore(7), 'the store\'s owner is itself a store customer');
        $this->assertSame(2, ResellerEligibility::MAX_TIER);
    }

    public function test_eligibility_reads_the_store_the_client_belongs_to(): void
    {
        $db = new ScriptedDatabase();
        $db->on('/FROM resellers r JOIN clients o/', static fn (array $b): array => match ((int) $b[0]) {
            1 => [['owner_store_id' => null]],
            2 => [['owner_store_id' => 1]],
            default => [],
        });
        $eligibility = new ResellerEligibility($db);

        $this->assertSame(1, $eligibility->tierFor(['id' => 9, 'reseller_id' => null]), 'a platform customer');
        $this->assertSame(2, $eligibility->tierFor(['id' => 9, 'reseller_id' => 1]), 'a customer of a partner store');
        $this->assertNull($eligibility->tierFor(['id' => 9, 'reseller_id' => 2]), 'a customer of a sub-reseller');
        $this->assertFalse($eligibility->canResell(['id' => 9, 'reseller_id' => 2]));
        $this->assertSame(1, $eligibility->tierFor(['id' => 9, 'reseller_id' => 99]), 'their store is gone');
    }

    public function test_the_reseller_area_paths(): void
    {
        $this->assertTrue(ResellerEligibility::isResellerAreaPath('/client/reseller'));
        $this->assertTrue(ResellerEligibility::isResellerAreaPath('/client/reseller/store'));
        $this->assertTrue(ResellerEligibility::isResellerAreaPath('/client/reseller/clients/4/login'));
        $this->assertFalse(ResellerEligibility::isResellerAreaPath('/client/resellerx'));
        $this->assertFalse(ResellerEligibility::isResellerAreaPath('/client/dashboard'));
        $this->assertFalse(ResellerEligibility::isResellerAreaPath('/admin/resellers'));
    }

    public function test_the_kernel_refuses_the_reseller_area_to_an_ineligible_client(): void
    {
        $kernel = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Kernel.php');

        $this->assertStringContainsString('ResellerEligibility::isResellerAreaPath', $kernel);
        $this->assertStringContainsString('canResell', $kernel);
    }

    public function test_the_dashboard_hides_the_reseller_link_from_an_ineligible_client(): void
    {
        $dashboard = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/client-auth/dashboard.php');

        $this->assertMatchesRegularExpression('/if \(\$canResell \?\? true\)[\s\S]*?\/client\/reseller/', $dashboard);
    }

    // ------------------------------------------------------------- admin stats ---

    public function test_overview_stats_count_stores_tiers_and_queues(): void
    {
        $stats = AdminResellerController::overviewStats([
            ['status' => 'active', 'customer_count' => 4, 'pending_orders' => 1, 'domain_verified_at' => '2026-01-01', 'upline_id' => null],
            ['status' => 'suspended', 'customer_count' => 2, 'pending_orders' => 0, 'domain_verified_at' => null, 'upline_id' => 1, 'upline_upline_id' => null],
            ['status' => 'active', 'customer_count' => '3', 'pending_orders' => '2', 'upline_id' => 2, 'upline_upline_id' => 1],
        ], ['domains' => 2, 'payouts' => 0, 'migrations' => null]);

        $this->assertSame(3, $stats['stores']);
        $this->assertSame(2, $stats['active']);
        $this->assertSame(9, $stats['customers']);
        $this->assertSame(3, $stats['pending_orders']);
        $this->assertSame(1, $stats['custom_domains']);
        $this->assertSame(2, $stats['sub_resellers']);
        $this->assertSame(1, $stats['third_tier'], 'a store under a sub-reseller is flagged as legacy');
        $this->assertSame(2, $stats['domain_requests']);
        $this->assertSame(0, $stats['payout_requests']);
        $this->assertNull($stats['migrations'], 'an uncountable queue is shown as unknown, not as zero');
    }

    // ------------------------------------------------------------------ views ---

    private function view(string $path): View
    {
        $_ENV['APP_URL'] = 'https://platform.test';
        $_SERVER['REQUEST_URI'] = $path;
        $config = new Config(sys_get_temp_dir() . '/codevault-views-noenv-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        App::setContainer($container);

        return new View(dirname(__DIR__, 2) . '/resources/views');
    }

    /** @param array<string, mixed> $data */
    private function render(string $path, string $template, array $data): string
    {
        $view = $this->view($path);
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
    private function overviewData(array $extra = []): array
    {
        $catalogue = [['product_id' => 5, 'name' => 'Starter', 'cycles' => [[
            'cycle' => 'monthly', 'label' => 'Monthly',
            'price' => ['list' => '$100.00', 'reseller' => '$80.00', 'discount_percent' => 20.0, 'discount_label' => '20%', 'saving' => '$20.00'],
        ]]]];

        return $extra + [
            'state' => 'none', 'credential' => null, 'issued' => null, 'error' => null, 'notice' => null,
            'discounts' => ['service' => 20.0, 'domain' => 10.0],
            'services' => $catalogue, 'domains' => [], 'currency' => ['code' => 'USD', 'symbol' => '$'],
            'docsUrl' => '/client/reseller/docs', 'userId' => 200, 'resellerId' => 2,
            'client' => ['id' => 200, 'first_name' => 'Bea'],
            'store' => $this->stores[2], 'upline' => null,
            'overview' => [
                'summary' => ['customers' => 3, 'services_active' => 5, 'services_suspended' => 1, 'domains' => 2, 'invoices_unpaid' => 1],
                'account' => ['balance' => 12.5, 'withdrawable' => 10.0, 'currency_code' => 'USD'],
                'pending_orders' => 4,
            ],
        ];
    }

    public function test_the_partner_overview_shows_our_discounts(): void
    {
        $html = $this->render('/client/reseller', 'reseller.client-index', $this->overviewData());

        $this->assertStringContainsString('Welcome back, Bea', $html);
        $this->assertStringContainsString('Service discount', $html);
        $this->assertStringContainsString('Your discount', $html);
        $this->assertStringContainsString('Partner reseller', $html);
        $this->assertStringContainsString('12.50 USD', $html, 'the earnings card');
        $this->assertStringContainsString('rs-stat__icon', $html);
        $this->assertSame(1, substr_count($html, 'rs-nav__link--active'));
    }

    public function test_the_sub_reseller_overview_shows_the_uplines_prices_not_ours(): void
    {
        $html = $this->render('/client/reseller', 'reseller.client-index', $this->overviewData([
            'upline' => ['id' => 1, 'slug' => 'alpha', 'brand_name' => 'Alpha <Hosting>'],
        ]));

        $this->assertStringContainsString('Sub-reseller of Alpha &lt;Hosting&gt;', $html);
        $this->assertStringNotContainsString('Alpha <Hosting>', $html);
        $this->assertStringContainsString('cannot open reseller accounts', $html);
        $this->assertStringNotContainsString('Service discount', $html, 'our discount is not theirs to quote');
        $this->assertStringNotContainsString('Your discount', $html);
    }

    public function test_the_overview_renders_before_a_store_is_opened(): void
    {
        $html = $this->render('/client/reseller', 'reseller.client-index', $this->overviewData([
            'store' => null, 'resellerId' => null,
            'overview' => ['summary' => null, 'account' => null, 'pending_orders' => null],
        ]));

        $this->assertStringContainsString('Open your store', $html);
        $this->assertStringNotContainsString('Earnings balance', $html);
    }

    public function test_the_admin_overview_has_stat_cards_and_tiers(): void
    {
        $stores = [
            ['id' => 1, 'client_id' => 100, 'slug' => 'alpha', 'brand_name' => 'Alpha', 'status' => 'active', 'customer_count' => 2, 'pending_orders' => 0, 'created_at' => '2026-01-01', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@x.test', 'upline_id' => null],
            ['id' => 2, 'client_id' => 200, 'slug' => 'beta', 'brand_name' => 'Beta', 'status' => 'active', 'customer_count' => 1, 'pending_orders' => 1, 'created_at' => '2026-01-02', 'first_name' => 'C', 'last_name' => 'D', 'email' => 'c@x.test', 'upline_id' => 1, 'upline_slug' => 'alpha', 'upline_brand' => 'Alpha', 'upline_upline_id' => null],
            ['id' => 3, 'client_id' => 300, 'slug' => 'gamma', 'brand_name' => null, 'status' => 'suspended', 'customer_count' => 0, 'pending_orders' => 0, 'created_at' => '2026-01-03', 'first_name' => 'E', 'last_name' => 'F', 'email' => 'e@x.test', 'upline_id' => 2, 'upline_slug' => 'beta', 'upline_brand' => null, 'upline_upline_id' => 1],
        ];

        $html = $this->render('/admin/resellers', 'reseller.admin-index', [
            'discounts' => ['service' => 20.0, 'domain' => 10.0],
            'resellers' => [], 'stores' => $stores, 'platformHost' => 'platform.test', 'activeCount' => 0,
            'error' => null, 'notice' => null, 'docsUrl' => '/admin/resellers/docs',
            'stats' => AdminResellerController::overviewStats($stores, ['domains' => 1, 'payouts' => 0, 'migrations' => 0]),
        ]);

        $this->assertStringContainsString('Reseller stores', $html);
        $this->assertStringContainsString('Sub-resellers', $html);
        $this->assertStringContainsString('Store customers', $html);
        $this->assertStringContainsString('Domain requests', $html);
        $this->assertStringContainsString('Legacy 3rd tier', $html);
        $this->assertSame(1, substr_count($html, 'Legacy 3rd tier'));
        $this->assertStringContainsString('of beta (#2)', $html, 'a brandless upline is named by its slug');
        $this->assertSame(1, substr_count($html, 'rs-nav__link--active'), 'the admin nav marks exactly one page');
    }

    public function test_the_admin_store_page_names_the_upline(): void
    {
        $html = $this->render('/admin/resellers/200/store', 'reseller.admin-store', [
            'clientId' => 200, 'store' => $this->stores[2] + ['status' => 'active', 'created_at' => '2026-01-02'],
            'client' => ['id' => 200, 'first_name' => 'Bea', 'last_name' => 'Lee', 'email' => 'b@x.test', 'reseller_id' => 1],
            'platformHost' => 'platform.test', 'platformUrl' => 'https://beta.platform.test', 'recordName' => null,
            'error' => null, 'notice' => null, 'verification' => null, 'markup' => 10.0, 'overrideCount' => 0,
            'discounts' => ['service' => 20.0, 'domain' => 10.0], 'goLive' => [], 'chat' => \CodeVault\Reseller\ResellerChat::formValues($this->stores[2]),
            'customerSummary' => ['customers' => 3, 'services_active' => 5, 'services_suspended' => 1, 'domains' => 2, 'invoices_unpaid' => 1],
            'pendingOrders' => 2,
            'upline' => $this->stores[1], 'legacyThirdTier' => true,
        ]);

        $this->assertStringContainsString('Sub-reseller of Alpha · Reseller ID 1', $html);
        $this->assertStringContainsString('Alpha\'s retail prices', $html);
        $this->assertStringContainsString('Third tier', $html);
        $this->assertStringContainsString('Active services', $html);
    }
}
