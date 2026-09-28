<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Cart\Cart;
use CodeVault\Cart\CartService;
use CodeVault\Cart\CheckoutController;
use CodeVault\Cart\CheckoutService;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerPricing;
use CodeVault\Reseller\ResellerRetailPriceRepository;
use CodeVault\Reseller\ResellerRetailPricing;
use CodeVault\Reseller\ResellerSettings;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Ordering at a reseller's store: what the customer is charged, what the
 * reseller owes, and who the account belongs to afterwards.
 *
 * These tests drive the REAL storefront path — a session cart priced by
 * CartService::priced(), placed by CheckoutService::placeOrder() — because that
 * is the only path where a store's prices apply. The admin's own
 * placeOrderForClient() deliberately does NOT apply a store (an admin order is
 * not a storefront sale), and asserting a store's prices through it would have
 * tested a path no customer ever takes.
 *
 * The property that matters most is that a PLATFORM order is unchanged. The
 * storefront is a new branch through code every customer already uses, so each
 * test proving a store charges retail is paired with one proving the platform
 * still charges list and records no cost.
 *
 * Cost is deliberately not a function of anything the reseller controls: it
 * comes from our catalogue and the admin's discount, so no store setting and no
 * promotion can reduce what we are owed.
 */
final class ResellerStoreCheckoutTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private ResellerStoreRepository $storeRepo;
    private ResellerStoreService $stores;
    private ResellerSettings $settings;
    private ResellerRetailPricing $retail;
    private SessionManager $session;
    private CurrentReseller $current;
    private Config $config;
    private Cart $cart;
    private int $resellerClientId;
    private int $customerId;
    private int $storeId;
    private int $productId;
    private string $cycle = 'monthly';

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $configDir = sys_get_temp_dir() . '/codevault-store-checkout-' . uniqid();
        mkdir($configDir);
        $this->config = new Config($configDir);

        $settings = new SettingsRepository($this->db);
        $this->settings = new ResellerSettings($settings);
        $cost = new ResellerPricing($this->db, $this->settings);
        $this->retail = new ResellerRetailPricing($cost, new ResellerRetailPriceRepository($this->db));

        $this->storeRepo = new ResellerStoreRepository($this->db);
        $this->stores = new ResellerStoreService(
            $this->storeRepo,
            new ResellerStoreLocator($this->storeRepo, $this->config),
            new DomainVerifier()
        );

        $this->clients = new ClientRepository($this->db);

        $this->resellerClientId = $this->clients->create([
            'email' => 'store-owner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Store',
            'last_name' => 'Owner',
        ]);

        $this->customerId = $this->clients->create([
            'email' => 'shopper@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Shop',
            'last_name' => 'Per',
        ]);

        $this->storeId = (int) $this->stores->openForClient($this->resellerClientId, 'Acme')['store']['id'];

        $this->seedCatalogue();

        $_SESSION = [];
        $this->session = new SessionManager($this->config);
        $this->current = new CurrentReseller();
        $this->cart = new Cart($this->session);

        $container = new Container();
        $container->instance(Database::class, $this->db);
        $container->instance(Config::class, $this->config);
        $container->instance(SessionManager::class, $this->session);
        $container->instance(SettingsRepository::class, $settings);
        $container->instance(CurrentReseller::class, $this->current);
        // The same cart the pricing service and the checkout read, so a line
        // added here is the line that gets priced and ordered.
        $container->instance(Cart::class, $this->cart);
        App::setContainer($container);
    }

    /** A 100.00/month plan and a .com at 20/20/25 — round numbers, obvious answers. */
    private function seedCatalogue(): void
    {
        $groups = new ProductGroupRepository($this->db);
        $products = new ProductRepository($this->db);
        $pricing = new ProductPricingRepository($this->db);

        $groupId = $groups->create('Hosting', 'Plans');
        $this->productId = $products->create([
            'product_group_id' => $groupId,
            'name' => 'Business Hosting',
            'pay_type' => 'paid',
        ]);
        $pricing->setPricing($this->productId, $this->cycle, 0.0, 100.0);

        (new DomainPricingRepository($this->db))->save([
            'tld' => '.com',
            'registrar_slug' => 'local',
            'register_price' => 20.0,
            'transfer_price' => 20.0,
            'renew_price' => 25.0,
        ]);
    }

    private function cartService(): CartService
    {
        return App::container()->make(CartService::class);
    }

    private function checkout(): CheckoutService
    {
        return App::container()->make(CheckoutService::class);
    }

    /** Adds the seeded plan to the session cart, exactly as the shop page does. */
    private function addToCart(int $quantity = 1): void
    {
        $this->cart->add($this->productId, $this->cycle, [], $quantity);
    }

    /** The storefront's own placement path: session cart in, order out. */
    private function placeStoreOrder(?string $promoCode = null): array
    {
        if ($promoCode !== null) {
            $this->cart->setPromoCode($promoCode);
        }

        return $this->checkout()->placeOrder($this->customerId);
    }

    /** Act as if the request arrived on this store's domain. */
    private function atStore(float $markup = 50.0): void
    {
        $this->settings->save(20.0, 10.0);
        $this->storeRepo->setMarkup($this->storeId, $markup);
        $this->current->set($this->storeRepo->find($this->storeId), 'acme.platform.test');
    }

    private function order(int $orderId): array
    {
        return (array) $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
    }

    /** @return array<int, array<string, mixed>> */
    private function orderItems(int $orderId): array
    {
        return $this->db->select('SELECT * FROM order_items WHERE order_id = ?', [$orderId]);
    }

    // --- the platform path must not move ------------------------------------

    public function test_the_platform_storefront_still_charges_list_with_no_reseller(): void
    {
        $this->assertFalse($this->current->exists(), 'no store is being served in this test');

        $this->addToCart();
        $priced = $this->cartService()->priced();

        $this->assertSame(100.0, $priced['total'], 'the platform charges the catalogue price');
        $this->assertSame(0.0, $priced['costTotal']);
        $this->assertNull($priced['store_id']);
        $this->assertNull($priced['lines'][0]['cost_price'], 'no store means no cost to record');

        $result = $this->placeStoreOrder();

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));

        $order = $this->order((int) $result['orderId']);

        $this->assertSame(100.0, (float) $order['total']);
        $this->assertNull($order['reseller_id'], 'a platform order belongs to no store');
        $this->assertNull($order['cost_total'], 'and owes no reseller anything');

        $items = $this->orderItems((int) $result['orderId']);

        $this->assertNull($items[0]['cost_price']);
        $this->assertSame(100.0, (float) $items[0]['unit_price']);
    }

    public function test_an_admin_order_for_a_client_is_not_a_store_sale(): void
    {
        // An admin raising an order on a client's behalf is not trading through
        // a storefront, even if the admin happens to be looking at one.
        $this->atStore(50.0);

        $result = $this->checkout()->placeOrderForClient($this->customerId, [[
            'product_id' => $this->productId,
            'billing_cycle' => $this->cycle,
            'quantity' => 1,
            'options' => [],
        ]]);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));

        $order = $this->order((int) $result['orderId']);

        $this->assertSame(100.0, (float) $order['total'], 'priced at catalogue, not at a store');
        $this->assertNull($order['reseller_id']);
        $this->assertNull($order['cost_total']);
    }

    // --- the store path -----------------------------------------------------

    public function test_a_store_checkout_charges_retail_and_records_our_cost(): void
    {
        $this->atStore(50.0);
        $this->addToCart();

        $priced = $this->cartService()->priced();

        // list 100, markup 50% -> customer pays 150; discount 20% -> cost 80.
        $this->assertSame(150.0, $priced['total']);
        $this->assertSame(80.0, $priced['costTotal']);
        $this->assertSame($this->storeId, $priced['store_id']);
        $this->assertSame(150.0, $priced['lines'][0]['unit_price']);
        $this->assertSame(80.0, $priced['lines'][0]['cost_price']);

        $result = $this->placeStoreOrder();

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));

        $order = $this->order((int) $result['orderId']);

        $this->assertSame(150.0, (float) $order['total'], 'the customer is charged retail');
        $this->assertSame(80.0, (float) $order['cost_total'], 'and the reseller owes us cost');
        $this->assertSame($this->storeId, (int) $order['reseller_id']);

        $items = $this->orderItems((int) $result['orderId']);

        $this->assertSame(150.0, (float) $items[0]['unit_price']);
        $this->assertSame(80.0, (float) $items[0]['cost_price']);

        // The customer's invoice is retail, like any other invoice — the cost is
        // between us and the reseller and must not appear as a charge on it.
        $invoice = (array) $this->db->selectOne('SELECT * FROM invoices WHERE order_id = ?', [(int) $result['orderId']]);

        $this->assertSame(150.0, (float) $invoice['total']);
    }

    public function test_a_hand_set_price_flows_through_the_cart(): void
    {
        $this->atStore(50.0);
        $this->retail->saveOverrides($this->storeId, [$this->productId . ':' . $this->cycle => 199.0], []);
        $this->addToCart();

        $priced = $this->cartService()->priced();

        $this->assertSame(199.0, $priced['total'], 'the override wins over the markup');
        $this->assertSame(80.0, $priced['costTotal'], 'and changes what we are owed not at all');
    }

    public function test_quantity_multiplies_the_customer_price_and_the_cost_together(): void
    {
        $this->atStore(50.0);
        $this->addToCart(3);

        $priced = $this->cartService()->priced();

        $this->assertSame(450.0, $priced['total']);
        $this->assertSame(240.0, $priced['costTotal'], 'three lots of 80');
    }

    public function test_a_promotion_discounts_the_customer_and_not_our_cost(): void
    {
        $this->atStore(50.0);
        $this->seedPromotion('SAVE10', 10.0);
        $this->addToCart();
        // The code lives on the cart — priced() reads it from there, exactly as
        // it does for a shopper who applied it on the cart page.
        $this->cart->setPromoCode('SAVE10');

        $priced = $this->cartService()->priced();

        // 10% off the 150 retail subtotal.
        $this->assertSame(15.0, $priced['discount']);
        $this->assertSame(135.0, $priced['total']);
        // The coupon is the reseller's own concession: our 80 is untouched.
        $this->assertSame(80.0, $priced['costTotal']);

        $result = $this->placeStoreOrder();

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));

        $order = $this->order((int) $result['orderId']);

        $this->assertSame(135.0, (float) $order['total']);
        $this->assertSame(80.0, (float) $order['cost_total'], 'a reseller campaign cannot spend our margin');
    }

    private function seedPromotion(string $code, float $percent): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->insert(
            'INSERT INTO promotions (code, type, value, min_order_amount, status, created_at, updated_at) VALUES (?, ?, ?, 0.00, ?, ?, ?)',
            [$code, 'percentage', $percent, 'active', $now, $now]
        );
    }

    // --- attribution --------------------------------------------------------

    public function test_a_customer_who_buys_at_a_store_belongs_to_that_store(): void
    {
        $this->atStore();
        $this->addToCart();

        $result = $this->placeStoreOrder();

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));

        $customer = (array) $this->clients->find($this->customerId);

        $this->assertSame($this->storeId, (int) $customer['reseller_id']);
    }

    public function test_a_store_cannot_capture_an_account_that_already_has_an_owner(): void
    {
        $this->atStore();
        $this->addToCart();

        // This customer already belongs to somebody else — an account we had
        // before the store existed, or one another reseller brought in.
        $otherStoreId = (int) $this->stores->openForClient(
            $this->clients->create([
                'email' => 'other-owner@example.test',
                'password' => 'correct-horse-battery',
                'first_name' => 'Other',
                'last_name' => 'Owner',
            ]),
            'Rival'
        )['store']['id'];

        $this->db->update('UPDATE clients SET reseller_id = ? WHERE id = ?', [$otherStoreId, $this->customerId]);

        $result = $this->placeStoreOrder();

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));

        $customer = (array) $this->clients->find($this->customerId);

        $this->assertSame($otherStoreId, (int) $customer['reseller_id'], 'the original owner keeps the account');

        // The ORDER is still attributed to the store that took it — ownership of
        // the account and attribution of the sale are separate facts.
        $this->assertSame($this->storeId, (int) $this->order((int) $result['orderId'])['reseller_id']);
    }

    public function test_the_claim_is_atomic_so_a_second_attempt_cannot_steal_an_account(): void
    {
        // Straight at the repository: the guard is `reseller_id IS NULL` in the
        // UPDATE, so the second claim reports failure rather than overwriting.
        $this->assertTrue($this->clients->setResellerIfUnclaimed($this->customerId, $this->storeId));
        $this->assertFalse($this->clients->setResellerIfUnclaimed($this->customerId, $this->storeId + 1));

        $customer = (array) $this->clients->find($this->customerId);

        $this->assertSame($this->storeId, (int) $customer['reseller_id']);
    }

    // --- the shelf label and the till must agree ----------------------------

    public function test_the_shop_cache_key_is_per_store(): void
    {
        $platform = CheckoutController::catalogueCacheKey(null);
        $store = CheckoutController::catalogueCacheKey(['id' => $this->storeId]);
        $other = CheckoutController::catalogueCacheKey(['id' => $this->storeId + 1]);

        // A shared key would serve one reseller's retail prices to another for
        // the cache's lifetime — a price leak with no visible symptom.
        $this->assertNotSame($platform, $store);
        $this->assertNotSame($store, $other);
        $this->assertSame($store, CheckoutController::catalogueCacheKey(['id' => $this->storeId]));
    }

    public function test_the_price_shown_and_the_price_charged_are_the_same_number(): void
    {
        $this->atStore(50.0);
        $this->addToCart();

        $store = $this->storeRepo->find($this->storeId);
        $markup = $this->retail->markupFor($store);

        // What the shop page labels the plan with...
        $shelf = $this->retail->priceFor(100.0, $markup, null);

        // ...must be what the cart charges for it.
        $charged = $this->cartService()->priced()['lines'][0]['unit_price'];

        $this->assertSame($shelf, $charged);
        $this->assertSame(150.0, $shelf);
    }

    public function test_without_a_store_the_price_shown_and_charged_are_still_list(): void
    {
        $this->addToCart();

        $this->assertSame(100.0, $this->cartService()->priced()['lines'][0]['unit_price']);
    }
}
