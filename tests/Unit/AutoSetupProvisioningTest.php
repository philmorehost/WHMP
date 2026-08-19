<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\OrderRepository;
use CodeVault\Billing\PaymentService;
use CodeVault\Billing\ServiceRepository;

use CodeVault\Cart\Cart;
use CodeVault\Cart\CartService;
use CodeVault\Cart\CheckoutService;
use CodeVault\Catalog\BillingCycle;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainRepository;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

final class AutoSetupProvisioningTest extends DatabaseTestCase
{
    private ProductRepository $products;
    private DomainPricingRepository $domainPricing;
    private ServiceRepository $services;
    private DomainRepository $domains;
    private CheckoutService $checkout;
    private PaymentService $paymentService;
    private InvoiceRepository $invoices;
    private OrderRepository $orders;
    private int $clientId;
    private int $serverGroupId;

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        $container = \CodeVault\Support\App::container();
        $container->instance(Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->products = new ProductRepository($this->db);
        $this->domainPricing = new DomainPricingRepository($this->db);
        $this->services = new ServiceRepository($this->db);
        $this->domains = new DomainRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->orders = new OrderRepository($this->db);

        $container->instance(ProductRepository::class, $this->products);
        $container->instance(DomainPricingRepository::class, $this->domainPricing);
        $container->instance(ServiceRepository::class, $this->services);
        $container->instance(DomainRepository::class, $this->domains);
        $container->instance(InvoiceRepository::class, $this->invoices);
        $container->instance(OrderRepository::class, $this->orders);

        $serverGroups = new ServerGroupRepository($this->db);
        $this->serverGroupId = $serverGroups->create('Test Group');
        $servers = new ServerRepository($this->db);
        $servers->create([
            'server_group_id' => $this->serverGroupId,
            'name' => 'Server 1',
            'hostname' => 'srv1.test.local',
            'module_slug' => 'local',
        ]);
        $container->instance(ServerRepository::class, $servers);
        $container->instance(ServerGroupRepository::class, $serverGroups);

        $localStorageDir = sys_get_temp_dir() . '/codevault-test-prov-' . uniqid();
        @mkdir($localStorageDir);
        $localModule = new \CodeVault\Provisioning\LocalProvisioningModule($localStorageDir);

        $hooks = $container->make(\CodeVault\Hooks\HookDispatcher::class);
        $modules = $container->make(\CodeVault\Modules\ModuleManager::class);
        $modules->register(\CodeVault\Modules\ProvisioningModule::class, 'local', $localModule);

        $provisioning = new \CodeVault\Provisioning\ProvisioningService($this->services, $this->products, $servers, $modules, $hooks);
        $container->instance(\CodeVault\Provisioning\ProvisioningService::class, $provisioning);

        $clients = new ClientRepository($this->db);
        $this->clientId = $clients->create([
            'email' => 'autosetup_user@example.test',
            'password' => 'password123',
            'first_name' => 'Auto',
            'last_name' => 'Setup',
        ]);
    }

    public function test_product_autosetup_on_order_provisions_immediately(): void
    {
        $groups = new ProductGroupRepository($this->db);
        $gId = $groups->create('Hosting', null);

        $productId = $this->products->create([
            'product_group_id' => $gId,
            'server_group_id' => $this->serverGroupId,
            'autosetup' => 'order',
            'name' => 'Order-Auto-Product',
            'status' => 'active',
        ]);

        $pricing = new ProductPricingRepository($this->db);
        $configDir = sys_get_temp_dir() . '/codevault-autosetup-test-' . uniqid();
        @mkdir($configDir);
        $session = new \CodeVault\Session\SessionManager(new \CodeVault\Config($configDir));
        $cart = new Cart($session);
        $cart->add($productId, BillingCycle::MONTHLY);

        $container = \CodeVault\Support\App::container();
        $cartService = new CartService($cart, $this->products, $pricing, $container->make(\CodeVault\Catalog\ConfigurableOptionRepository::class), $container->make(\CodeVault\Catalog\ConfigurableOptionPricingRepository::class), $container->make(\CodeVault\Billing\PromotionService::class), $this->db);
        $checkout = new CheckoutService(
            $cart,
            $cartService,
            $this->products,
            new ClientRepository($this->db),
            $this->services,
            $container->make(\CodeVault\Billing\TaxCalculator::class),
            $container->make(\CodeVault\Billing\CurrencyService::class),
            $container->make(\CodeVault\Billing\CurrencySelection::class),
            $container->make(\CodeVault\Billing\PromotionRepository::class),
            $this->db,
            $container->make(\CodeVault\Hooks\HookDispatcher::class),
            $container->make(\CodeVault\Domains\DomainSettings::class)
        );

        $res = $checkout->placeOrder($this->clientId);
        $this->assertTrue($res['success']);

        $serviceId = $res['serviceIds'][0];
        $service = $this->services->find($serviceId);

        $this->assertSame('active', $service['status'], 'Service with autosetup=order must be active immediately after checkout');
    }

    public function test_product_autosetup_on_payment_provisions_after_invoice_is_paid(): void
    {
        $groups = new ProductGroupRepository($this->db);
        $gId = $groups->create('Hosting', null);

        $productId = $this->products->create([
            'product_group_id' => $gId,
            'server_group_id' => $this->serverGroupId,
            'autosetup' => 'payment',
            'name' => 'Payment-Auto-Product',
            'status' => 'active',
        ]);

        $pricing = new ProductPricingRepository($this->db);
        $configDir = sys_get_temp_dir() . '/codevault-autosetup-test-' . uniqid();
        @mkdir($configDir);
        $session = new \CodeVault\Session\SessionManager(new \CodeVault\Config($configDir));
        $cart = new Cart($session);
        $cart->add($productId, BillingCycle::MONTHLY);

        $container = \CodeVault\Support\App::container();
        $cartService = new CartService($cart, $this->products, $pricing, $container->make(\CodeVault\Catalog\ConfigurableOptionRepository::class), $container->make(\CodeVault\Catalog\ConfigurableOptionPricingRepository::class), $container->make(\CodeVault\Billing\PromotionService::class), $this->db);
        $checkout = new CheckoutService(
            $cart,
            $cartService,
            $this->products,
            new ClientRepository($this->db),
            $this->services,
            $container->make(\CodeVault\Billing\TaxCalculator::class),
            $container->make(\CodeVault\Billing\CurrencyService::class),
            $container->make(\CodeVault\Billing\CurrencySelection::class),
            $container->make(\CodeVault\Billing\PromotionRepository::class),
            $this->db,
            $container->make(\CodeVault\Hooks\HookDispatcher::class),
            $container->make(\CodeVault\Domains\DomainSettings::class)
        );

        $res = $checkout->placeOrder($this->clientId);
        $this->assertTrue($res['success']);

        $serviceId = $res['serviceIds'][0];
        $invoiceId = $res['invoiceId'];

        $serviceBefore = $this->services->find($serviceId);
        $this->assertSame('pending', $serviceBefore['status'], 'Service with autosetup=payment must be pending before payment');

        $hooks = $container->make(\CodeVault\Hooks\HookDispatcher::class);
        $paymentService = new PaymentService($this->invoices, new \CodeVault\Billing\TransactionRepository($this->db), $hooks);
        $paymentService->recordPayment($invoiceId, 'manual', 15.0);

        $serviceAfter = $this->services->find($serviceId);
        $this->assertSame('active', $serviceAfter['status'], 'Service with autosetup=payment must become active after payment');
    }
}
