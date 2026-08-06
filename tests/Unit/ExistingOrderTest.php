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
use CodeVault\Domains\DomainRepository;
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

/**
 * The "existing service/domain" admin order mode: records something the
 * client already owns without raising an invoice. An existing order lands as
 * `active` with `no_invoice = 1`, its services/domains are created `active`
 * (already live) rather than `pending`, stock is untouched, and the prices
 * passed in are still written to the order/service/domain rows so the
 * records show the item's worth.
 */
final class ExistingOrderTest extends DatabaseTestCase
{
    private CheckoutService $checkout;
    private CartService $cartService;
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
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-existing-order-test-' . uniqid();
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

        $this->cartService = new CartService($cart, $this->products, $this->pricing, $options, $optionPricing, $promotionService, $this->db);
        $this->checkout = new CheckoutService($cart, $this->cartService, $this->products, $this->clients, $services, $tax, $currency, $currencySelection, $promotions, $this->db, new HookDispatcher(), new DomainSettings(new SettingsRepository($this->db)));

        $groups = new ProductGroupRepository($this->db);
        $groupId = $groups->create('Hosting', null);
        $this->productId = $this->products->create(['product_group_id' => $groupId, 'name' => 'Starter', 'stock_quantity' => 5]);
        $this->pricing->setPricing($this->productId, 'monthly', 0.00, 10.00);

        $this->clientId = $this->clients->create([
            'email' => 'existing-order-buyer@example.test',
            'password' => 'secret123',
            'first_name' => 'Buyer',
            'last_name' => 'Person',
        ]);

        $this->domainPricing = new DomainPricingRepository($this->db);
        $registrars = new RegistrarRepository($this->db);
        $this->localStorageDir = sys_get_temp_dir() . '/codevault-existing-order-registrar-' . uniqid();
        $localModule = new LocalRegistrarModule($this->localStorageDir);
        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $modules->register(RegistrarModule::class, 'local', $localModule);
        $this->domainService = new DomainService(new DomainRepository($this->db), $registrars, $modules, $hooks, $this->clients, $this->db, new ActivityLogger($this->db));
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

    private function productItem(int $productId, string $cycle = 'monthly', int $qty = 1): array
    {
        return [
            'product_id' => $productId,
            'billing_cycle' => $cycle,
            'quantity' => $qty,
            'options' => [],
            'domain_options' => null,
            'server_options' => null,
            'custom_fields' => null,
        ];
    }

    public function test_existing_service_order_records_no_invoice_and_active_service(): void
    {
        $result = $this->checkout->placeOrderForClient($this->clientId, [$this->productItem($this->productId)], null, true);

        $this->assertTrue($result['success']);
        $this->assertSame(0, (int) $result['invoiceId']);

        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $this->assertSame('active', $order['status']);
        $this->assertSame(1, (int) $order['no_invoice']);

        // No invoice row anywhere for this order.
        $invoiceCount = $this->db->selectOne('SELECT COUNT(*) AS c FROM invoices WHERE order_id = ?', [$result['orderId']]);
        $this->assertSame(0, (int) $invoiceCount['c']);

        // The service records what the client already has: active, price kept.
        $service = $this->db->selectOne('SELECT * FROM services WHERE order_id = ?', [$result['orderId']]);
        $this->assertSame('active', $service['status']);
        $this->assertEqualsWithDelta(10.00, (float) $service['amount'], 0.01);

        // Order total is the item value (no tax is added — no invoice exists).
        $this->assertEqualsWithDelta(10.00, (float) $order['total'], 0.01);

        // Stock was NOT consumed.
        $product = $this->products->find($this->productId);
        $this->assertSame(5, (int) $product['stock_quantity']);
    }

    public function test_existing_service_order_allows_a_sold_out_product(): void
    {
        // Drain stock — an existing order must still record the service.
        $this->products->decrementStock($this->productId);
        $this->products->decrementStock($this->productId);
        $this->products->decrementStock($this->productId);
        $this->products->decrementStock($this->productId);
        $this->products->decrementStock($this->productId);

        $result = $this->checkout->placeOrderForClient($this->clientId, [$this->productItem($this->productId)], null, true);

        $this->assertTrue($result['success']);
        $service = $this->db->selectOne('SELECT * FROM services WHERE order_id = ?', [$result['orderId']]);
        $this->assertSame('active', $service['status']);

        // And a normal (non-existing) order for the same sold-out product is
        // still rejected — the guard only relaxes for existing orders.
        $normal = $this->checkout->placeOrderForClient($this->clientId, [$this->productItem($this->productId)]);
        $this->assertFalse($normal['success']);
        $this->assertStringContainsString('out of stock', $normal['error']);
    }

    public function test_existing_domain_only_order_records_the_domain_as_active(): void
    {
        $this->domainPricing->save([
            'tld' => '.test',
            'registrar_slug' => 'local',
            'register_price' => 12.50,
            'transfer_price' => 12.50,
            'renew_price' => 12.50,
        ]);

        $item = [
            'product_id' => $this->domainService->carrierProductId(),
            'billing_cycle' => 'annually',
            'quantity' => 1,
            'options' => [],
            'domain_options' => [
                'name' => 'kept.test',
                'option' => 'existing',
                'price' => 12.50,
                'ns1' => 'ns1.example.test',
                'ns2' => 'ns2.example.test',
            ],
            'server_options' => null,
            'custom_fields' => null,
        ];

        $result = $this->checkout->placeOrderForClient($this->clientId, [$item], null, true);

        $this->assertTrue($result['success']);
        $this->assertSame(0, (int) $result['invoiceId']);

        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $this->assertSame('active', $order['status']);
        $this->assertSame(1, (int) $order['no_invoice']);
        $this->assertEqualsWithDelta(12.50, (float) $order['total'], 0.01);

        // The domain is recorded as already-live, at the given price.
        $domain = $this->db->selectOne('SELECT * FROM domains WHERE order_id = ?', [$result['orderId']]);
        $this->assertNotNull($domain);
        $this->assertSame('kept.test', $domain['domain_name']);
        $this->assertSame('active', $domain['status']);
        $this->assertEqualsWithDelta(12.50, (float) $domain['amount'], 0.01);
    }

    public function test_existing_domain_order_rejected_when_domain_name_already_in_system(): void
    {
        $this->domainPricing->save([
            'tld' => '.test',
            'registrar_slug' => 'local',
            'register_price' => 12.50,
            'transfer_price' => 12.50,
            'renew_price' => 12.50,
        ]);

        $item = [
            'product_id' => $this->domainService->carrierProductId(),
            'billing_cycle' => 'annually',
            'quantity' => 1,
            'options' => [],
            'domain_options' => [
                'name' => 'dupe.test',
                'option' => 'existing',
                'price' => 12.50,
                'ns1' => 'ns1.example.test',
                'ns2' => 'ns2.example.test',
            ],
            'server_options' => null,
            'custom_fields' => null,
        ];

        // First recording succeeds.
        $first = $this->checkout->placeOrderForClient($this->clientId, [$item], null, true);
        $this->assertTrue($first['success']);

        // Recording the same domain again must not 500 on the UNIQUE
        // domains.domain_name index — the whole order is rolled back and a
        // friendly error is returned.
        $second = $this->checkout->placeOrderForClient($this->clientId, [$item], null, true);
        $this->assertFalse($second['success']);
        $this->assertStringContainsString('dupe.test', (string) $second['error']);
        $this->assertStringContainsString('already in the system', (string) $second['error']);

        // No stray order or domain row was left behind by the failed attempt.
        $orderCount = $this->db->selectOne('SELECT COUNT(*) AS c FROM orders');
        $this->assertSame(1, (int) $orderCount['c']);
        $domainCount = $this->db->selectOne('SELECT COUNT(*) AS c FROM domains');
        $this->assertSame(1, (int) $domainCount['c']);
    }

    public function test_duplicate_domain_guard_covers_register_orders_too(): void
    {
        $this->domainPricing->save([
            'tld' => '.test',
            'registrar_slug' => 'local',
            'register_price' => 12.50,
            'transfer_price' => 12.50,
            'renew_price' => 12.50,
        ]);

        $item = [
            'product_id' => $this->domainService->carrierProductId(),
            'billing_cycle' => 'annually',
            'quantity' => 1,
            'options' => [],
            'domain_options' => [
                'name' => 'register-dupe.test',
                'option' => 'register',
                'ns1' => 'ns1.example.test',
                'ns2' => 'ns2.example.test',
            ],
            'server_options' => null,
            'custom_fields' => null,
        ];

        $first = $this->checkout->placeOrderForClient($this->clientId, [$item]);
        $this->assertTrue($first['success']);

        $second = $this->checkout->placeOrderForClient($this->clientId, [$item]);
        $this->assertFalse($second['success']);
        $this->assertStringContainsString('already in the system', (string) $second['error']);
    }

    public function test_normal_order_still_raises_an_invoice_and_consumes_stock(): void
    {
        $result = $this->checkout->placeOrderForClient($this->clientId, [$this->productItem($this->productId)]);

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, (int) $result['invoiceId']);

        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $this->assertSame('pending', $order['status']);
        $this->assertSame(0, (int) $order['no_invoice']);

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$result['invoiceId']]);
        $this->assertNotNull($invoice);

        // Stock WAS consumed for a normal order.
        $product = $this->products->find($this->productId);
        $this->assertSame(4, (int) $product['stock_quantity']);
    }
}
