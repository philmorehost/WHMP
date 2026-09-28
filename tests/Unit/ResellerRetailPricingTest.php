<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Request;
use CodeVault\Reseller\ClientResellerController;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerRetailPriceRepository;
use CodeVault\Reseller\ResellerRetailPricing;
use CodeVault\Reseller\ResellerSettings;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Reseller\ResellerPricing;
use CodeVault\Reseller\ResellerCredentialService;
use CodeVault\Api\DatabaseApiCredentialRepository;
use CodeVault\Activity\ActivityLogger;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\Theme\ThemeSettings;
use CodeVault\View;

/**
 * The reseller's retail prices: what their customers pay, and the margin they
 * keep on top of the discount we give them.
 *
 * Three figures meet here and the tests are mostly about keeping them apart —
 * list (ours), cost (what the reseller owes), retail (what the customer pays).
 * The one that must never be wrong is cost: it is what we are owed, and it is
 * derived from the admin's discount rather than from anything the reseller
 * types. A reseller can price their store however they like; they cannot
 * change what they owe us.
 */
final class ResellerRetailPricingTest extends DatabaseTestCase
{
    private ResellerSettings $settings;
    private ResellerPricing $cost;
    private ResellerRetailPricing $retail;
    private ResellerStoreService $stores;
    private ResellerStoreRepository $storeRepo;
    private ClientResellerController $controller;
    private ClientRepository $clients;
    private SessionManager $session;
    private CurrentReseller $current;
    private int $clientId;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $configDir = sys_get_temp_dir() . '/codevault-retail-test-' . uniqid();
        mkdir($configDir);
        $config = new Config($configDir);

        $settings = new SettingsRepository($this->db);
        $this->settings = new ResellerSettings($settings);
        $this->cost = new ResellerPricing($this->db, $this->settings);
        $this->retail = new ResellerRetailPricing($this->cost, new ResellerRetailPriceRepository($this->db));

        $storeRepo = new ResellerStoreRepository($this->db);
        $this->storeRepo = $storeRepo;
        $this->stores = new ResellerStoreService($storeRepo, new ResellerStoreLocator($storeRepo, $config), new DomainVerifier());

        $this->clients = new ClientRepository($this->db);
        $this->clientId = $this->clients->create([
            'email' => 'pricer@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Price',
            'last_name' => 'Setter',
        ]);

        $this->storeId = (int) $this->stores->openForClient($this->clientId, 'Acme')['store']['id'];

        $this->seedCatalogue();

        $this->current = new CurrentReseller();
        $_SESSION = [];
        $this->session = new SessionManager($config);
        $guard = new ClientAuthGuard($this->session, $this->clients);
        $_SESSION['client_id'] = $this->clientId;

        $container = new Container();
        $container->instance(SessionManager::class, $this->session);
        $container->instance(Database::class, $this->db);
        $container->instance(SettingsRepository::class, $settings);
        $container->instance(CurrentReseller::class, $this->current);
        App::setContainer($container);

        $this->controller = new ClientResellerController(
            $guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->session,
            new ResellerCredentialService(new DatabaseApiCredentialRepository($this->db)),
            $this->stores,
            new ResellerStoreLocator($storeRepo, $config),
            $this->retail,
            $this->settings,
            $this->cost,
            new CurrencyService(new CurrencyRepository($this->db)),
            new ActivityLogger($this->db)
        );
    }

    /**
     * One product priced at 100 on monthly, and a .com at 20/20/25 — round
     * numbers so a wrong answer in a test is obviously wrong.
     */
    private function seedCatalogue(): void
    {
        $groups = new ProductGroupRepository($this->db);
        $products = new ProductRepository($this->db);
        $pricing = new ProductPricingRepository($this->db);

        $groupId = $groups->create('Hosting', 'Plans');
        $productId = $products->create([
            'product_group_id' => $groupId,
            'name' => 'Business Hosting',
            'pay_type' => 'paid',
        ]);
        $pricing->setPricing($productId, 'monthly', 10.0, 100.0);

        (new DomainPricingRepository($this->db))->save([
            'tld' => '.com',
            'registrar_slug' => 'local',
            'register_price' => 20.0,
            'transfer_price' => 20.0,
            'renew_price' => 25.0,
        ]);
    }

    /** @return array<string, mixed> */
    private function store(): array
    {
        return (array) $this->stores->forClient($this->clientId);
    }

    /**
     * The override key for this test's monthly plan, as the catalogue spells it.
     *
     * Taken from the catalogue rather than hard-coded as "1:monthly": the
     * migrations seed products, so this test's own product does not get id 1 —
     * and a key that silently does not match makes savePrices ignore the input,
     * which is how a below-cost price appeared to be accepted.
     */
    private function hostingKey(): string
    {
        foreach ($this->retail->previewServices($this->store()) as $product) {
            if ($product['name'] !== 'Business Hosting') {
                continue;
            }

            foreach ($product['cycles'] as $cycle) {
                if ($cycle['cycle'] === 'monthly') {
                    return (int) $product['product_id'] . ':monthly';
                }
            }
        }

        $this->fail('Business Hosting (monthly) is missing from the catalogue');
    }

    // --- the three numbers -------------------------------------------------

    public function test_the_default_is_no_markup_so_retail_equals_list(): void
    {
        $this->settings->save(20.0, 10.0);

        $quote = $this->retail->quoteProduct(100.0, $this->store(), 1, 'monthly');

        $this->assertSame(0.0, $quote['markup_percent']);
        $this->assertSame(100.0, $quote['list']);
        $this->assertSame(100.0, $quote['retail'], 'a new store must not silently add a markup');
        $this->assertSame(80.0, $quote['cost'], 'cost is list less the admin discount');
        $this->assertSame(20.0, $quote['margin'], 'the margin starts as our discount');
    }

    public function test_markup_adds_to_list_and_the_margin_keeps_both(): void
    {
        $this->settings->save(20.0, 10.0);
        $this->storeRepo->setMarkup($this->storeId, 25.0);

        $quote = $this->retail->quoteProduct(100.0, $this->store(), 1, 'monthly');

        $this->assertSame(125.0, $quote['retail'], 'retail is list plus markup, not cost plus markup');
        $this->assertSame(80.0, $quote['cost']);
        $this->assertSame(45.0, $quote['margin'], 'the reseller keeps the markup AND our discount');
        $this->assertSame(25.0, $quote['markup_percent']);
    }

    public function test_an_override_beats_the_markup(): void
    {
        $this->settings->save(20.0, 10.0);
        $this->storeRepo->setMarkup($this->storeId, 25.0);

        $this->retail->saveOverrides($this->storeId, [$this->hostingKey() => 99.99], []);

        $quote = $this->retail->quoteProduct(100.0, $this->store(), (int) explode(':', $this->hostingKey())[0], 'monthly');

        $this->assertSame(99.99, $quote['retail'], 'a hand-set price wins outright');
        $this->assertTrue($quote['overridden']);
        $this->assertSame(19.99, $quote['margin']);
    }

    public function test_markup_is_clamped_and_an_extreme_markup_cannot_go_negative(): void
    {
        $this->assertSame(0.0, ResellerRetailPricing::clampMarkup(-50.0));
        $this->assertSame(ResellerRetailPricing::MAX_MARKUP, ResellerRetailPricing::clampMarkup(999999.0));
        $this->assertSame(12.35, ResellerRetailPricing::clampMarkup(12.345));

        // A negative markup loaded into the row (an old record, a hand-edited
        // database) must not produce a price below list.
        $this->db->update('UPDATE resellers SET markup_percent = ? WHERE id = ?', [-25.0, $this->storeId]);

        $this->assertSame(0.0, $this->retail->markupFor($this->store()));
        $this->assertSame(100.0, $this->retail->quoteProduct(100.0, $this->store(), 1, 'monthly')['retail']);
    }

    // --- overrides ---------------------------------------------------------

    public function test_a_price_below_cost_is_refused_with_a_reason(): void
    {
        $this->settings->save(20.0, 10.0);

        // Cost of a 100.00 monthly plan is 80.00.
        $problem = $this->retail->overrideProblem(79.99, 80.0, 'Business Hosting (Monthly)');

        $this->assertNotNull($problem);
        $this->assertStringContainsString('below the', $problem);
        $this->assertStringContainsString('you would owe more than you collect', strtolower($problem));

        // Exactly cost is allowed — no margin, but no debt either.
        $this->assertNull($this->retail->overrideProblem(80.0, 80.0, 'Business Hosting (Monthly)'));
        $this->assertNull($this->retail->overrideProblem(500.0, 80.0, 'Business Hosting (Monthly)'));

        // Zero and negative are not prices.
        $this->assertNotNull($this->retail->overrideProblem(0.0, 80.0, 'x'));
        $this->assertNotNull($this->retail->overrideProblem(-5.0, 80.0, 'x'));
    }

    public function test_saving_a_below_cost_price_list_is_refused_whole(): void
    {
        $this->settings->save(20.0, 10.0);

        $response = $this->controller->savePrices($this->post([
            'markup_percent' => '25',
            'price' => [$this->hostingKey() => '10.00'],
        ]));

        $this->assertSame(302, $response->status());

        $store = $this->store();

        // Nothing applied — not even the markup, which was itself valid: a
        // half-saved price list is worse than a rejected one.
        $this->assertSame(0.0, (float) $store['markup_percent']);
        $this->assertSame([], (new ResellerRetailPriceRepository($this->db))->productOverrides($this->storeId));
        $this->assertStringContainsString('Nothing was saved', (string) $this->session->pullFlash('reseller_error'));
    }

    public function test_a_valid_price_list_is_saved_markup_overrides_and_domains(): void
    {
        $this->settings->save(20.0, 10.0);

        $response = $this->controller->savePrices($this->post([
            'markup_percent' => '25',
            'price' => [$this->hostingKey() => '149.00'],
            'domain' => ['.com.register' => '30.00', '.com.transfer' => '', '.com.renew' => '35.00'],
        ]));

        $this->assertSame(302, $response->status());

        $store = $this->store();
        $this->assertSame(25.0, (float) $store['markup_percent']);

        $products = (new ResellerRetailPriceRepository($this->db))->productOverrides($this->storeId);
        $this->assertSame([$this->hostingKey() => 149.0], $products);

        $domains = (new ResellerRetailPriceRepository($this->db))->domainOverrides($this->storeId);
        $this->assertSame(30.0, $domains['.com']['register']);
        $this->assertSame(35.0, $domains['.com']['renew']);
        $this->assertNull($domains['.com']['transfer'], 'a blank field clears that override');

        // And a follow-up save with the override removed clears it.
        $this->controller->savePrices($this->post([
            'markup_percent' => '25',
            'price' => [$this->hostingKey() => ''],
        ]));

        $this->assertSame([], (new ResellerRetailPriceRepository($this->db))->productOverrides($this->storeId));
    }

    public function test_a_posted_key_that_is_not_in_the_catalogue_is_ignored(): void
    {
        $this->settings->save(20.0, 10.0);

        $this->controller->savePrices($this->post([
            'markup_percent' => '0',
            // A product that does not exist, and a TLD that does not exist.
            'price' => ['9999:monthly' => '5.00'],
            'domain' => ['.evil' => '1.00'],
        ]));

        $repo = new ResellerRetailPriceRepository($this->db);

        $this->assertSame([], $repo->productOverrides($this->storeId));
        $this->assertSame([], $repo->domainOverrides($this->storeId));
    }

    // --- the preview -------------------------------------------------------

    public function test_the_preview_shows_every_cycle_at_its_retail_price(): void
    {
        $this->settings->save(20.0, 10.0);
        $this->storeRepo->setMarkup($this->storeId, 50.0);

        $services = $this->retail->previewServices($this->store());

        $product = null;

        foreach ($services as $row) {
            if ($row['name'] === 'Business Hosting') {
                $product = $row;
            }
        }

        $this->assertNotNull($product);

        $monthly = null;

        foreach ($product['cycles'] as $cycle) {
            if ($cycle['cycle'] === 'monthly') {
                $monthly = $cycle;
            }
        }

        $this->assertNotNull($monthly);
        $this->assertSame(100.0, $monthly['retail']['list']);
        $this->assertSame(80.0, $monthly['retail']['cost']);
        $this->assertSame(150.0, $monthly['retail']['retail']);

        $domains = $this->retail->previewDomains($this->store());
        $com = null;

        foreach ($domains as $row) {
            if ($row['tld'] === '.com') {
                $com = $row;
            }
        }

        $this->assertNotNull($com);
        // Domain discount is 10% here (the service discount is 20%), so a $20
        // registration costs 18 and a $25 renewal costs 22.50.
        $this->assertSame(30.0, $com['register_retail']['retail'], 'domains take the markup too');
        $this->assertSame(18.0, $com['register_retail']['cost'], 'and keep their own discount rate');
        $this->assertSame(37.5, $com['renew_retail']['retail']);
        $this->assertSame(22.5, $com['renew_retail']['cost']);
    }

    public function test_changing_the_admin_discount_moves_cost_without_touching_the_resellers_prices(): void
    {
        $this->settings->save(20.0, 10.0);
        $this->storeRepo->setMarkup($this->storeId, 25.0);

        $before = $this->retail->quoteProduct(100.0, $this->store(), 1, 'monthly');

        // The admin cuts the reseller discount: our cost goes UP, their retail
        // price is untouched — the reseller keeps the price they published.
        $this->settings->save(5.0, 10.0);

        $after = $this->retail->quoteProduct(100.0, $this->store(), 1, 'monthly');

        $this->assertSame(125.0, $after['retail']);
        $this->assertSame($before['retail'], $after['retail']);
        $this->assertSame(95.0, $after['cost'], 'cost follows the discount');
        $this->assertSame(30.0, $after['margin'], 'and so the margin shrinks');

        // A printed price that no longer covers cost is REPORTED, not rewritten.
        $this->assertTrue($this->retail->belowCost(90.0, 95.0));
        $this->assertFalse($this->retail->belowCost(95.0, 95.0));
    }

    public function test_the_price_page_renders_the_three_numbers_and_the_not_live_warning(): void
    {
        $this->settings->save(20.0, 10.0);

        $page = (string) $this->controller->prices($this->getRequest())->body();

        $this->assertStringContainsString('Your Prices', $page);
        $this->assertStringContainsString('not live yet', $page);
        $this->assertStringContainsString('Business Hosting', $page);
        $this->assertStringContainsString('20.00%', $page, 'the reseller discount is stated');
        // The three figures, formatted, so a wrong number is visible to them.
        $this->assertStringContainsString('$100.00', $page, 'list');
        $this->assertStringContainsString('$80.00', $page, 'cost');
    }

    private function post(array $body): Request
    {
        return new Request([], $body, [
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_URI' => '/client/reseller/prices',
        ], []);
    }

    private function getRequest(): Request
    {
        return new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_URI' => '/client/reseller/prices',
        ], []);
    }
}
