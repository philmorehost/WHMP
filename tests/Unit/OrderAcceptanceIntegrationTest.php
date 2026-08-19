<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\AcceptOrderJob;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\PaymentService;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Cart\CartService;
use CodeVault\Cart\CheckoutService;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainRepository;
use CodeVault\Domains\DomainService;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Mail\Mailer;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Modules\RegistrarModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Tests\Fixtures\FakeRegistrarModule;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\Billing\TransactionRepository;

/**
 * End-to-end acceptance of an order that contains BOTH a shared-hosting
 * service and a new domain registration — the exact scenario from the user
 * report "admin approves an order with service + domain; only the hosting
 * account is provisioned, the domain is never registered until the admin
 * registers it by hand".
 *
 * This drives the REAL checkout (CheckoutService::placeOrderForClient), the
 * REAL invoice-payment hook (INVOICE_PAID), and the REAL accept job
 * (AcceptOrderJob) — no shortcuts — so a regression anywhere in the chain
 * shows up here.
 */
final class OrderAcceptanceIntegrationTest extends DatabaseTestCase
{
    private int $clientId;
    private int $adminId;
    private string $adminEmail = 'ops@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        $container = \CodeVault\Support\App::container();
        $container->instance(Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        // Record outbound mail in-memory instead of attempting SMTP.
        $sentMails = [];
        $container->instance(Mailer::class, new class ($sentMails) implements Mailer {
            /** @param array<int, array{to: string, subject: string, html: string}> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function send(string $to, string $subject, string $html): void
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
            }
        });

        $clients = new ClientRepository($this->db);
        $this->clientId = $clients->create([
            'email' => 'integration@example.test',
            'password' => 'password123',
            'first_name' => 'Int',
            'last_name' => 'Test',
        ]);

        $this->adminId = (new AdminRepository($this->db))->create('ops', $this->adminEmail, 'secret123', 'Ops Admin', null);

        // A working server group so the hosting service can actually provision.
        $serverGroups = new ServerGroupRepository($this->db);
        $serverGroupId = $serverGroups->create('Hosting Group');
        (new ServerRepository($this->db))->create([
            'server_group_id' => $serverGroupId,
            'name' => 'Hosting Server',
            'hostname' => 'srv.test.local',
            'module_slug' => 'local',
        ]);
        $container->instance(ServerRepository::class, new ServerRepository($this->db));
        $container->instance(ServerGroupRepository::class, $serverGroups);

        // Local provisioning module (writes a file, no network) + fake registrar.
        $localStorageDir = sys_get_temp_dir() . '/codevault-integration-prov-' . uniqid();
        @mkdir($localStorageDir);
        $modules = $container->make(ModuleManager::class);
        $modules->register(ProvisioningModule::class, 'local', new \CodeVault\Provisioning\LocalProvisioningModule($localStorageDir));
        $this->fakeRegistrar = new FakeRegistrarModule();
        $modules->register(RegistrarModule::class, 'fake', $this->fakeRegistrar);
    }

    private FakeRegistrarModule $fakeRegistrar;

    private function productItem(int $productId, string $cycle = 'monthly'): array
    {
        return [
            'product_id' => $productId,
            'billing_cycle' => $cycle,
            'quantity' => 1,
            'options' => [],
            'domain_options' => null,
            'server_options' => null,
            'custom_fields' => null,
        ];
    }

    private function domainItem(int $carrierProductId, string $name): array
    {
        return [
            'product_id' => $carrierProductId,
            'billing_cycle' => 'annually',
            'quantity' => 1,
            'options' => [],
            'domain_options' => [
                'name' => $name,
                'option' => 'register',
                'price' => 12.50,
                'ns1' => 'ns1.example.test',
                'ns2' => 'ns2.example.test',
            ],
            'server_options' => null,
            'custom_fields' => null,
        ];
    }

    private function buildCheckout(): CheckoutService
    {
        $container = \CodeVault\Support\App::container();

        $configDir = sys_get_temp_dir() . '/cv-int-' . uniqid();
        @mkdir($configDir);
        $cart = new \CodeVault\Cart\Cart(new \CodeVault\Session\SessionManager(new \CodeVault\Config($configDir)));
        $cartService = new CartService(
            $cart,
            new ProductRepository($this->db),
            new ProductPricingRepository($this->db),
            $container->make(\CodeVault\Catalog\ConfigurableOptionRepository::class),
            $container->make(\CodeVault\Catalog\ConfigurableOptionPricingRepository::class),
            $container->make(\CodeVault\Billing\PromotionService::class),
            $this->db
        );

        return new CheckoutService(
            $cart,
            $cartService,
            new ProductRepository($this->db),
            new ClientRepository($this->db),
            new ServiceRepository($this->db),
            $container->make(\CodeVault\Billing\TaxCalculator::class),
            $container->make(\CodeVault\Billing\CurrencyService::class),
            $container->make(\CodeVault\Billing\CurrencySelection::class),
            $container->make(\CodeVault\Billing\PromotionRepository::class),
            $this->db,
            $container->make(HookDispatcher::class),
            $container->make(\CodeVault\Domains\DomainSettings::class)
        );
    }

    /**
     * The full happy path: a client orders shared hosting + a brand-new
     * domain, the invoice is paid, and the admin then accepts the order.
     * Both items must end up provisioned — the hosting service active AND
     * the domain registered (status active via the fake registrar).
     */
    public function test_hosting_and_domain_are_both_provisioned_by_accept(): void
    {
        $container = \CodeVault\Support\App::container();

        $groups = new ProductGroupRepository($this->db);
        $productGroupId = $groups->create('Hosting', null);
        $serverGroupId = (new ServerGroupRepository($this->db))->findByName('Hosting Group')['id'] ?? $this->db->selectOne('SELECT id FROM server_groups WHERE name = ?', ['Hosting Group'])['id'];

        $products = new ProductRepository($this->db);
        $hostingId = $products->create([
            'product_group_id' => $productGroupId,
            'server_group_id' => $serverGroupId,
            'name' => 'Shared Hosting',
            'autosetup' => 'on_accept',
        ]);
        $pricing = new ProductPricingRepository($this->db);
        $pricing->setPricing($hostingId, 'monthly', 0.0, 15.0);

        (new DomainPricingRepository($this->db))->save([
            'tld' => '.test',
            'registrar_slug' => 'fake',
            'register_price' => 12.50,
            'transfer_price' => 12.50,
            'renew_price' => 12.50,
            'autosetup_registration' => 'payment',
        ]);

        $carrierProductId = $container->make(DomainService::class)->carrierProductId();
        $this->assertGreaterThan(0, $carrierProductId);

        $checkout = $this->buildCheckout();
        $result = $checkout->placeOrderForClient($this->clientId, [
            $this->productItem($hostingId),
            $this->domainItem($carrierProductId, 'hosted.test'),
        ]);

        $this->assertTrue($result['success'], 'checkout must succeed');
        $orderId = (int) $result['orderId'];
        $invoiceId = (int) $result['invoiceId'];
        $this->assertGreaterThan(0, $invoiceId);

        // Both rows exist, pending, attached to the order.
        $services = $this->db->select('SELECT * FROM services WHERE order_id = ?', [$orderId]);
        $this->assertCount(2, $services, 'hosting + carrier domain service');
        foreach ($services as $s) {
            $this->assertSame('pending', $s['status']);
        }

        $domains = $this->db->select('SELECT * FROM domains WHERE order_id = ?', [$orderId]);
        $this->assertCount(1, $domains);
        $this->assertSame('pending', $domains[0]['status']);
        $this->assertSame('hosted.test', $domains[0]['domain_name']);
        $this->assertSame('fake', $domains[0]['registrar_slug']);

        // Client pays the invoice → the payment hook auto-registers the
        // domain (autosetup 'payment') and leaves on_accept hosting pending.
        $paymentService = new PaymentService(
            new InvoiceRepository($this->db),
            new TransactionRepository($this->db),
            $container->make(HookDispatcher::class)
        );
        $paymentService->recordPayment($invoiceId, 'manual', (float) $this->db->selectOne('SELECT total FROM invoices WHERE id = ?', [$invoiceId])['total']);

        $domainAfterPay = $this->db->selectOne('SELECT * FROM domains WHERE order_id = ?', [$orderId]);
        $this->assertSame('active', $domainAfterPay['status'], 'domain must register at invoice payment (autosetup=payment)');

        // Admin accepts → the accept job provisions the hosting service.
        (new AcceptOrderJob($orderId, $this->adminId, '203.0.113.10'))->handle();

        $hostingService = null;
        foreach ($services as $s) {
            if ((int) $s['product_id'] === $hostingId) {
                $hostingService = $this->db->selectOne('SELECT * FROM services WHERE id = ?', [$s['id']]);
            }
        }
        $this->assertNotNull($hostingService);
        $this->assertSame('active', $hostingService['status'], 'on_accept hosting must be provisioned by the accept job');

        $domainFinal = $this->db->selectOne('SELECT * FROM domains WHERE order_id = ?', [$orderId]);
        $this->assertSame('active', $domainFinal['status'], 'domain must remain registered');
        $this->assertNotEmpty($this->fakeRegistrar->lastCall('register'), 'the fake registrar must have been called');
    }
}
