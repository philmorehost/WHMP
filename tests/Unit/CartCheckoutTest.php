<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencySelection;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\PromotionRepository;
use CodeVault\Billing\PromotionService;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Cart\Cart;
use CodeVault\Cart\CartService;
use CodeVault\Cart\CheckoutService;
use CodeVault\Catalog\ConfigurableOptionGroupRepository;
use CodeVault\Catalog\ConfigurableOptionPricingRepository;
use CodeVault\Catalog\ConfigurableOptionRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainSettings;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Session\SessionManager;
use CodeVault\Tests\Support\DatabaseTestCase;

final class CartCheckoutTest extends DatabaseTestCase
{
    private Cart $cart;
    private CartService $cartService;
    private CheckoutService $checkout;
    private CurrencySelection $currencySelection;
    private ProductRepository $products;
    private PromotionRepository $promotions;
    private int $clientId;
    private int $productId;
    private string $emptyConfigDir;

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        \CodeVault\Support\App::container()->instance(\CodeVault\Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $_SESSION = [];
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-cart-test-' . uniqid();
        mkdir($this->emptyConfigDir);
        $session = new SessionManager(new Config($this->emptyConfigDir));

        $this->cart = new Cart($session);
        $this->products = new ProductRepository($this->db);
        $pricing = new ProductPricingRepository($this->db);
        $options = new ConfigurableOptionRepository($this->db);
        $optionPricing = new ConfigurableOptionPricingRepository($this->db);
        $clients = new ClientRepository($this->db);
        $services = new ServiceRepository($this->db);
        $tax = new TaxCalculator(new TaxRuleRepository($this->db), new VatNumberValidator(), new TaxSettings(new SettingsRepository($this->db)));
        $currency = new CurrencyService(new CurrencyRepository($this->db));
        $this->currencySelection = new CurrencySelection($session);

        $this->promotions = new PromotionRepository($this->db);
        $promotionService = new PromotionService($this->promotions);

        $this->cartService = new CartService($this->cart, $this->products, $pricing, $options, $optionPricing, $promotionService, $this->db);
        $this->checkout = new CheckoutService($this->cart, $this->cartService, $this->products, $clients, $services, $tax, $currency, $this->currencySelection, $this->promotions, $this->db, new HookDispatcher(), new DomainSettings(new SettingsRepository($this->db)));

        $groups = new ProductGroupRepository($this->db);
        $groupId = $groups->create('Hosting', null);
        $this->productId = $this->products->create(['product_group_id' => $groupId, 'name' => 'Starter', 'stock_quantity' => 2]);
        $pricing->setPricing($this->productId, 'monthly', 3.00, 9.99);

        $this->clientId = $clients->create([
            'email' => 'buyer@example.test',
            'password' => 'secret123',
            'first_name' => 'Buyer',
            'last_name' => 'Person',
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->emptyConfigDir);
        parent::tearDown();
    }

    public function test_priced_cart_computes_line_total_including_setup_fee(): void
    {
        $this->cart->add($this->productId, 'monthly', [], 2);

        $priced = $this->cartService->priced();

        $this->assertCount(1, $priced['lines']);
        // (9.99 * 2 qty) + 3.00 setup fee
        $this->assertEqualsWithDelta(22.98, $priced['lines'][0]['line_total'], 0.001);
        $this->assertEqualsWithDelta(19.98, $priced['subtotal'], 0.001);
        $this->assertEqualsWithDelta(3.00, $priced['setupFees'], 0.001);
    }

    public function test_place_order_creates_order_and_invoice_and_clears_cart(): void
    {
        $this->cart->add($this->productId, 'monthly', [], 1);

        $result = $this->checkout->placeOrder($this->clientId);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->cart->count());

        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $this->assertSame('pending', $order['status']);

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$result['invoiceId']]);
        $this->assertSame('unpaid', $invoice['status']);
        $this->assertEqualsWithDelta(12.99, (float) $invoice['total'], 0.001);

        $items = $this->db->select('SELECT * FROM order_items WHERE order_id = ?', [$result['orderId']]);
        $this->assertCount(1, $items);

        // Default-currency checkout stores NULL, not the default currency's own id.
        $this->assertNull($order['currency_id']);
        $this->assertNull($invoice['currency_id']);
        $this->assertEqualsWithDelta(1.0, (float) $order['currency_rate'], 0.0001);
    }

    public function test_place_order_locks_the_clients_non_default_currency_rate(): void
    {
        $currencies = new \CodeVault\Billing\CurrencyRepository($this->db);
        $eurId = $currencies->create('EUR', '€', 0.9200);
        $clients = new ClientRepository($this->db);
        $clients->updateCurrency($this->clientId, $eurId);

        $this->cart->add($this->productId, 'monthly', [], 1);
        $result = $this->checkout->placeOrder($this->clientId);

        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$result['invoiceId']]);

        $this->assertSame($eurId, (int) $order['currency_id']);
        // CheckoutService converts the catalog price into the client's
        // currency exactly once (CheckoutService::convertPriced(), at order
        // creation) and then locks the resulting amount at rate 1.0 via
        // denominateColumns() — the STORED total is already final, so
        // nothing downstream should ever multiply it by a live FX rate
        // again. The locked rate being 1.0 here does not mean no conversion
        // happened; it means the conversion already happened and is baked
        // into the amount itself. See the amount assertion below for the
        // actual conversion check.
        $this->assertEqualsWithDelta(1.0000, (float) $order['currency_rate'], 0.0001);
        $this->assertSame($eurId, (int) $invoice['currency_id']);
        $this->assertEqualsWithDelta(1.0000, (float) $invoice['currency_rate'], 0.0001);

        // Changing the currency's live rate afterwards must not alter what was already locked.
        $currencies->update($eurId, 'EUR', '€', 0.5000);
        $unchangedOrder = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $this->assertEqualsWithDelta(1.0000, (float) $unchangedOrder['currency_rate'], 0.0001);
    }

    public function test_place_order_locks_the_in_session_currency_even_when_it_differs_from_the_clients_saved_preference(): void
    {
        // Regression: a guest can switch currency (session) before logging
        // in/registering, then check out — the order must lock to what
        // they were actually shown at checkout, not their (possibly still
        // default/unset) saved profile currency.
        $currencies = new \CodeVault\Billing\CurrencyRepository($this->db);
        $gbpId = $currencies->create('GBP', '£', 0.7900);
        // Client's saved preference stays NULL/default — only the session differs.
        $this->currencySelection->set($gbpId);

        $this->cart->add($this->productId, 'monthly', [], 1);
        $result = $this->checkout->placeOrder($this->clientId);

        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$result['invoiceId']]);

        $this->assertSame($gbpId, (int) $order['currency_id']);
        // Locked at 1.0 because the amount was already converted once at
        // creation — see the note in the previous test.
        $this->assertEqualsWithDelta(1.0000, (float) $order['currency_rate'], 0.0001);
        $this->assertSame($gbpId, (int) $invoice['currency_id']);
    }

    /**
     * The actual reported bug: a client shopping in NGN saw a correctly
     * converted price on the cart page, but the placed order stored the
     * raw, unconverted base-currency figure — a $9.99 plan invoiced at
     * ₦9.99 instead of the correct ₦-equivalent at the live rate.
     */
    public function test_place_order_converts_the_catalog_price_into_the_clients_currency(): void
    {
        $currencies = new \CodeVault\Billing\CurrencyRepository($this->db);
        $ngnId = $currencies->create('NGN', '₦', 1490.0000);
        $clients = new ClientRepository($this->db);
        $clients->updateCurrency($this->clientId, $ngnId);

        $this->cart->add($this->productId, 'monthly', [], 1);
        $result = $this->checkout->placeOrder($this->clientId);

        $this->assertTrue($result['success']);

        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$result['invoiceId']]);
        $service = $this->db->selectOne('SELECT * FROM services WHERE order_id = ?', [$result['orderId']]);

        // 9.99 price + 3.00 setup fee = 12.99 base, converted at 1490 -> 19,355.10
        $this->assertEqualsWithDelta(19355.10, (float) $invoice['total'], 0.01);
        $this->assertEqualsWithDelta(19355.10, (float) $order['total'], 0.01);

        // The recurring service amount (no setup fee, that's one-time) —
        // 9.99 * 1490 = 14,885.10 — must also be converted, since this is
        // exactly the figure RecurringBillingService will read back
        // unconverted (denominateFor()) on every future renewal.
        $this->assertEqualsWithDelta(14885.10, (float) $service['amount'], 0.01);

        $items = $this->db->select('SELECT * FROM order_items WHERE order_id = ?', [$result['orderId']]);
        $this->assertEqualsWithDelta(14885.10, (float) $items[0]['unit_price'], 0.01);
    }

    public function test_place_order_records_the_domain_price_on_a_standalone_domain_order_item(): void
    {
        // A standalone domain rides on the hidden $0 "Domain Registration"
        // carrier product (seeded by migration 0103). Its real charge is the
        // domain's own price — the order item must carry that amount, or the
        // admin order page shows 0.00 for a charged domain and the revenue
        // report silently omits every domain registration.
        $pricing = new DomainPricingRepository($this->db);
        $pricing->save(['tld' => '.com.ng', 'registrar_slug' => 'local', 'register_price' => 15.00, 'transfer_price' => 15.00, 'renew_price' => 15.00]);

        $carrier = $this->db->selectOne("SELECT id FROM products WHERE name = 'Domain Registration' AND status = 'hidden' LIMIT 1");
        $this->assertNotNull($carrier, 'Migration 0103 seeds the hidden Domain Registration carrier product.');

        $this->cart->add((int) $carrier['id'], 'annually', [], 1, [
            'option' => 'register',
            'name' => 'example.com.ng',
            'ns1' => 'ns1.example.test',
            'ns2' => 'ns2.example.test',
        ]);

        $result = $this->checkout->placeOrder($this->clientId);

        $this->assertTrue($result['success']);

        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $this->assertEqualsWithDelta(15.00, (float) $order['total'], 0.0001);

        $items = $this->db->select('SELECT * FROM order_items WHERE order_id = ?', [$result['orderId']]);
        $this->assertCount(1, $items);
        $this->assertEqualsWithDelta(15.00, (float) $items[0]['unit_price'], 0.0001);
    }

    public function test_place_order_decrements_finite_stock(): void
    {
        $this->cart->add($this->productId, 'monthly', [], 1);
        $this->checkout->placeOrder($this->clientId);

        $this->assertSame(1, (int) $this->products->find($this->productId)['stock_quantity']);
    }

    public function test_place_order_fails_when_out_of_stock(): void
    {
        // Deplete the product's stock (started at 2) before the customer checks out.
        $this->products->decrementStock($this->productId);
        $this->products->decrementStock($this->productId);
        $this->assertFalse($this->products->hasUnlimitedOrAvailableStock($this->productId));

        $this->cart->add($this->productId, 'monthly', [], 1);
        $result = $this->checkout->placeOrder($this->clientId);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('out of stock', $result['error']);
    }

    public function test_empty_cart_cannot_checkout(): void
    {
        $result = $this->checkout->placeOrder($this->clientId);

        $this->assertFalse($result['success']);
        $this->assertSame('Your cart is empty.', $result['error']);
    }

    public function test_place_order_rolls_back_when_stock_is_lost_between_the_precheck_and_the_transaction(): void
    {
        // Regression: decrementStock()'s return value used to go unchecked
        // inside the transaction, so a concurrent checkout that won the
        // race for the last unit — passing the pre-transaction 'in_stock'
        // read, then losing the atomic decrement moments later — would
        // still charge the client for a unit that no longer existed. A
        // true race can't be driven deterministically from one thread, so
        // this exercises the exact same code path buildOrder() runs by
        // invoking it directly with a $priced snapshot that's already
        // stale relative to the DB (mirroring what a lost race produces).
        $stalePriced = [
            'lines' => [[
                'product_id' => $this->productId,
                'product_name' => 'Starter',
                'billing_cycle' => 'monthly',
                'cycle_label' => 'Monthly',
                'quantity' => 1,
                'unit_price' => 9.99,
                'setup_fee' => 0.0,
                'options' => [],
                'options_total' => 0.0,
                'line_total' => 9.99,
                'in_stock' => true,
            ]],
            'subtotal' => 9.99,
            'setupFees' => 0.0,
            'total' => 9.99,
        ];

        // Zero out the real stock — what a concurrent winning checkout
        // would have already done by the time this one's transaction runs.
        $this->products->decrementStock($this->productId);
        $this->products->decrementStock($this->productId);
        $this->assertSame(0, (int) $this->products->find($this->productId)['stock_quantity']);

        $method = new \ReflectionMethod($this->checkout, 'buildOrder');
        $method->setAccessible(true);
        $tax = ['rate' => 0.0, 'name' => 'Tax', 'amount' => 0.0];
        $currencyLock = ['currency_id' => null, 'currency_rate' => 1.0];

        $thrown = null;

        try {
            // Wrapped in a real transaction, exactly like placeOrder() does
            // at the real call site — this is what actually gives the
            // rollback its effect; buildOrder() alone just throws.
            $this->db->transaction(function () use ($method, $stalePriced, $tax, $currencyLock) {
                return $method->invoke($this->checkout, $stalePriced, $this->clientId, $tax, $currencyLock);
            });
        } catch (\CodeVault\Cart\OutOfStockException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'buildOrder() must throw OutOfStockException when the atomic decrement fails');
        $this->assertStringContainsString('just sold out', $thrown->getMessage());

        $orders = $this->db->select('SELECT * FROM orders WHERE client_id = ?', [$this->clientId]);
        $this->assertCount(0, $orders, 'the transaction rollback must leave no order row behind');
    }

    public function test_priced_cart_applies_a_percentage_promo_code_to_the_subtotal_only(): void
    {
        $this->promotions->save(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10]);
        $this->cart->add($this->productId, 'monthly', [], 1);
        $this->cart->setPromoCode('save10');

        $priced = $this->cartService->priced();

        // 9.99 subtotal * 10% = 0.999, rounded to 1.00
        $this->assertEqualsWithDelta(1.00, $priced['discount'], 0.001);
        $this->assertSame('SAVE10', $priced['promoCode']);
        $this->assertNull($priced['promoError']);
        // subtotal (9.99) + setupFees (3.00) - discount (1.00)
        $this->assertEqualsWithDelta(11.99, $priced['total'], 0.001);
    }

    public function test_priced_cart_reports_an_error_for_an_invalid_promo_code_without_discounting(): void
    {
        $this->cart->add($this->productId, 'monthly', [], 1);
        $this->cart->setPromoCode('DOESNOTEXIST');

        $priced = $this->cartService->priced();

        $this->assertSame(0.0, $priced['discount']);
        $this->assertNotNull($priced['promoError']);
        $this->assertEqualsWithDelta(12.99, $priced['total'], 0.001);
    }

    public function test_place_order_persists_the_discount_and_increments_redemptions(): void
    {
        $this->promotions->save(['code' => 'FLAT5', 'type' => 'fixed', 'value' => 5]);
        $promotion = $this->promotions->findByCode('FLAT5');

        $this->cart->add($this->productId, 'monthly', [], 1);
        $this->cart->setPromoCode('FLAT5');

        $result = $this->checkout->placeOrder($this->clientId);

        $this->assertTrue($result['success']);

        $order = $this->db->selectOne('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$result['invoiceId']]);

        $this->assertEqualsWithDelta(5.00, (float) $order['discount_amount'], 0.001);
        $this->assertSame('FLAT5', $order['promotion_code']);
        $this->assertEqualsWithDelta(5.00, (float) $invoice['discount_amount'], 0.001);
        // 9.99 subtotal + 3.00 setup - 5.00 discount
        $this->assertEqualsWithDelta(7.99, (float) $invoice['total'], 0.001);

        $items = $this->db->select('SELECT * FROM invoice_items WHERE invoice_id = ? AND amount < 0', [$result['invoiceId']]);
        $this->assertCount(1, $items);
        $this->assertStringContainsString('FLAT5', $items[0]['description']);

        $updated = $this->promotions->find((int) $promotion['id']);
        $this->assertSame(1, (int) $updated['redemption_count']);

        // The promo code doesn't survive past a completed checkout — a
        // fresh cart shouldn't silently re-apply the last code used.
        $this->assertNull($this->cart->promoCode());
    }

    public function test_promo_code_below_minimum_order_amount_is_rejected(): void
    {
        $this->promotions->save(['code' => 'BIGORDER', 'type' => 'fixed', 'value' => 5, 'min_order_amount' => 100]);
        $this->cart->add($this->productId, 'monthly', [], 1);
        $this->cart->setPromoCode('BIGORDER');

        $priced = $this->cartService->priced();

        $this->assertSame(0.0, $priced['discount']);
        $this->assertStringContainsString('minimum order', $priced['promoError']);
    }
}
