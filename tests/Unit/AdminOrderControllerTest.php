<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencySelection;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\PromotionRepository;
use CodeVault\Billing\PromotionService;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Cart\Cart;
use CodeVault\Cart\CartService;
use CodeVault\Cart\CheckoutService;
use CodeVault\Catalog\ConfigurableOptionPricingRepository;
use CodeVault\Catalog\ConfigurableOptionRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainService;
use CodeVault\Domains\DomainSettings;
use CodeVault\Domains\LocalRegistrarModule;
use CodeVault\Domains\RegistrarRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\RegistrarModule;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

final class AdminOrderControllerTest extends DatabaseTestCase
{
    private CheckoutService $checkout;
    private ClientRepository $clients;
    private ProductRepository $products;
    private ProductPricingRepository $pricing;
    private DomainPricingRepository $domainPricing;
    private DomainService $domainService;
    private string $emptyConfigDir;
    private string $localStorageDir;
    private int $clientId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        \CodeVault\Support\App::container()->instance(\CodeVault\Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $_SESSION = [];
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-admin-order-test-' . uniqid();
        mkdir($this->emptyConfigDir);
        $session = new SessionManager(new Config($this->emptyConfigDir));

        $cart = new Cart($session);
        $this->products = new ProductRepository($this->db);
        $this->pricing = new ProductPricingRepository($this->db);
        $options = new ConfigurableOptionRepository($this->db);
        $optionPricing = new ConfigurableOptionPricingRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $services = new ServiceRepository($this->db);
        $tax = new TaxCalculator(new TaxRuleRepository($this->db), new VatNumberValidator(), new TaxSettings(new SettingsRepository($this->db)));
        $currency = new CurrencyService(new CurrencyRepository($this->db));
        $currencySelection = new CurrencySelection($session);

        $promotions = new PromotionRepository($this->db);
        $promotionService = new PromotionService($promotions);

        $cartService = new CartService($cart, $this->products, $this->pricing, $options, $optionPricing, $promotionService, $this->db);
        $this->checkout = new CheckoutService($cart, $cartService, $this->products, $this->clients, $services, $tax, $currency, $currencySelection, $promotions, $this->db, new HookDispatcher(), new DomainSettings(new SettingsRepository($this->db)));

        $groups = new ProductGroupRepository($this->db);
        $groupId = $groups->create('Hosting', null);
        $this->productId = $this->products->create(['product_group_id' => $groupId, 'name' => 'Starter', 'stock_quantity' => 5]);
        $this->pricing->setPricing($this->productId, 'monthly', 0.00, 10.00);

        $this->clientId = $this->clients->create([
            'email' => 'adminorder-buyer@example.test',
            'password' => 'secret123',
            'first_name' => 'Buyer',
            'last_name' => 'Person',
        ]);

        $this->domainPricing = new DomainPricingRepository($this->db);
        $registrars = new RegistrarRepository($this->db);
        $this->localStorageDir = sys_get_temp_dir() . '/codevault-admin-order-registrar-' . uniqid();
        $localModule = new LocalRegistrarModule($this->localStorageDir);
        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $modules->register(RegistrarModule::class, 'local', $localModule);
        $this->domainService = new DomainService(new \CodeVault\Domains\DomainRepository($this->db), $registrars, $modules, $hooks, $this->clients, $this->db, new ActivityLogger($this->db), new \CodeVault\Clients\ClientContactRepository($this->db));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->emptyConfigDir);
        foreach (glob($this->localStorageDir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->localStorageDir);
        parent::tearDown();
    }

    /**
     * The whole point of routing admin-created orders through
     * CheckoutService::placeOrderForClient() (which itself delegates to the
     * same executeOrder()/convertPriced() path as normal storefront
     * checkout) instead of a bespoke order-building path is that it can
     * never drift from the Part 1 currency fix. This proves the shared path
     * actually converts for an admin-initiated order, not just a client one.
     */
    public function test_place_order_for_client_converts_the_catalog_price_into_the_clients_currency(): void
    {
        $currencies = new CurrencyRepository($this->db);
        $ngnId = $currencies->create('NGN', '₦', 1490.0000);
        $this->clients->updateCurrency($this->clientId, $ngnId);

        $items = [[
            'product_id' => $this->productId,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'options' => [],
            'domain_options' => null,
            'server_options' => null,
            'custom_fields' => null,
        ]];

        $result = $this->checkout->placeOrderForClient($this->clientId, $items);

        $this->assertTrue($result['success']);

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$result['invoiceId']]);
        $service = $this->db->selectOne('SELECT * FROM services WHERE order_id = ?', [$result['orderId']]);

        // 10.00 * 1490 = 14,900.00
        $this->assertEqualsWithDelta(14900.00, (float) $invoice['total'], 0.01);
        $this->assertEqualsWithDelta(14900.00, (float) $service['amount'], 0.01);
        $this->assertSame($ngnId, (int) $invoice['currency_id']);
    }

    public function test_place_order_for_client_does_not_touch_the_admins_own_session_cart(): void
    {
        $items = [[
            'product_id' => $this->productId,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'options' => [],
            'domain_options' => null,
            'server_options' => null,
            'custom_fields' => null,
        ]];

        $result = $this->checkout->placeOrderForClient($this->clientId, $items);

        $this->assertTrue($result['success']);
        // placeOrderForClient() must never clear/touch the session cart —
        // there is no "session" for the admin acting on someone else's order.
        $this->assertSame(0, (new Cart(new SessionManager(new Config($this->emptyConfigDir))))->count());
    }

    /**
     * Mirrors the safety check CheckoutController::addToCart() and
     * DomainRegistrationController::addToCart() already apply: a domain
     * that's already taken (per the registrar) must be rejected, not
     * silently queued into an order.
     */
    public function test_checkAvailability_rejects_an_already_registered_domain(): void
    {
        $this->domainPricing->save([
            'tld' => '.test',
            'registrar_slug' => 'local',
            'register_price' => 10.00,
            'transfer_price' => 10.00,
            'renew_price' => 10.00,
        ]);

        $localModule = new LocalRegistrarModule($this->localStorageDir);
        $localModule->register(['domain' => 'taken.test', 'years' => 1]);

        $check = $this->domainService->checkAvailability('taken.test', 'local');

        $this->assertTrue($check['success']);
        $this->assertFalse($check['available']);
    }

    public function test_checkAvailability_accepts_an_unregistered_domain(): void
    {
        $this->domainPricing->save([
            'tld' => '.test',
            'registrar_slug' => 'local',
            'register_price' => 10.00,
            'transfer_price' => 10.00,
            'renew_price' => 10.00,
        ]);

        $check = $this->domainService->checkAvailability('freshname.test', 'local');

        $this->assertTrue($check['success']);
        $this->assertTrue($check['available']);
    }
}
