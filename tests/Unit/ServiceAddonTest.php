<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ClientServiceController;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\ProrationService;
use CodeVault\Billing\ServiceAddonService;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductAddonRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Session\SessionManager;
use CodeVault\Support\App;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketService;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;
use DateTimeImmutable;

/**
 * Recurring service add-ons (blueprint gap: no product/service add-ons —
 * the biggest revenue lever). Locks in: an add-on is a child services row
 * (parent_id) billed on its own cycle; admin config gates what's offered;
 * a client can order and remove add-ons; ordering raises a first-period
 * invoice; the recurring billing job picks the child row up later.
 */
final class ServiceAddonTest extends DatabaseTestCase
{
    private ClientServiceController $controller;
    private ServiceRepository $services;
    private ProductAddonRepository $addonConfig;
    private ServiceAddonService $addonService;
    private int $clientId;
    private int $groupId;
    private int $parentProductId;
    private int $addonProductId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $clients = new ClientRepository($this->db);
        $this->clientId = $clients->create([
            'email' => 'addon-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Addon',
            'last_name' => 'Tester',
        ]);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->groupId = (int) $this->db->insert(
            'INSERT INTO product_groups (name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['Shared Hosting', 0, $now, $now]
        );
        $this->parentProductId = $this->insertProduct('Starter', 10.00, $now);
        $this->addonProductId = $this->insertProduct('Extra IP', 5.00, $now);
        // A second add-on with a setup fee.
        $backupId = $this->insertProduct('Offsite Backup', 3.00, $now);
        $this->db->update('UPDATE product_pricing SET setup_fee = ? WHERE product_id = ? AND billing_cycle = ?', [2.00, $backupId, 'monthly']);

        $this->services = new ServiceRepository($this->db);
        $this->addonConfig = new ProductAddonRepository($this->db);
        $this->addonConfig->attach($this->parentProductId, $this->addonProductId, null, 0);
        $this->addonConfig->attach($this->parentProductId, $backupId, null, 1);

        $configDir = sys_get_temp_dir() . '/codevault-client-addon-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $guard = new ClientAuthGuard($session, $clients);
        $guard->login($clients->find($this->clientId));

        $container = new \CodeVault\Container();
        $container->instance(SessionManager::class, $session);
        $container->instance(Database::class, $this->db);
        App::setContainer($container);

        $currency = new CurrencyService(new CurrencyRepository($this->db));
        $this->addonService = new ServiceAddonService(
            $this->services,
            $clients,
            new \CodeVault\Billing\TaxCalculator(
                new \CodeVault\Billing\TaxRuleRepository($this->db),
                new \CodeVault\Billing\VatNumberValidator(),
                new \CodeVault\Billing\TaxSettings(new \CodeVault\Settings\SettingsRepository($this->db))
            ),
            $currency,
            $this->db,
            new HookDispatcher()
        );

        $this->controller = new ClientServiceController(
            $guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->services,
            new ProvisioningService(
                $this->services,
                new ProductRepository($this->db),
                new ServerRepository($this->db),
                new \CodeVault\Modules\ModuleManager(new HookDispatcher()),
                new HookDispatcher()
            ),
            new ServerRepository($this->db),
            $currency,
            new \CodeVault\Billing\CancellationRequestRepository($this->db),
            new \CodeVault\Billing\InvoiceRepository($this->db),
            new \CodeVault\Activity\ActivityLogger($this->db),
            new TicketService(
                new \CodeVault\Support\TicketRepository($this->db),
                new \CodeVault\Support\TicketReplyRepository($this->db),
                new HookDispatcher(),
                new \CodeVault\Support\TicketAttachmentRepository($this->db)
            ),
            new DepartmentRepository($this->db),
            new AddonModuleRepository($this->db),
            $this->db,
            new ProductRepository($this->db),
            new ProductPricingRepository($this->db),
            $this->proration(),
            $this->addonConfig,
            $this->addonService
        );
    }

    private function proration(): ProrationService
    {
        return new ProrationService(
            $this->services,
            new ClientRepository($this->db),
            new \CodeVault\Billing\TaxCalculator(
                new \CodeVault\Billing\TaxRuleRepository($this->db),
                new \CodeVault\Billing\VatNumberValidator(),
                new \CodeVault\Billing\TaxSettings(new \CodeVault\Settings\SettingsRepository($this->db))
            ),
            new \CodeVault\Billing\ClientCreditRepository($this->db),
            new \CodeVault\Billing\CreditService(
                new \CodeVault\Billing\ClientCreditRepository($this->db),
                new \CodeVault\Billing\InvoiceRepository($this->db),
                new \CodeVault\Billing\TransactionRepository($this->db),
                new \CodeVault\Billing\PaymentService(
                    new \CodeVault\Billing\InvoiceRepository($this->db),
                    new \CodeVault\Billing\TransactionRepository($this->db),
                    new HookDispatcher()
                ),
                new HookDispatcher()
            ),
            new CurrencyService(new CurrencyRepository($this->db)),
            $this->db,
            new HookDispatcher()
        );
    }

    private function insertProduct(string $name, float $price, string $now): int
    {
        $productId = (int) $this->db->insert(
            'INSERT INTO products (product_group_id, name, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$this->groupId, $name, 'active', 0, $now, $now]
        );

        $this->db->insert(
            'INSERT INTO product_pricing (product_id, billing_cycle, setup_fee, price) VALUES (?, ?, ?, ?)',
            [$productId, 'monthly', 0, $price]
        );

        return $productId;
    }

    private function activeService(): int
    {
        $nextDue = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
        return (int) $this->db->insert(
            "INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, domain, next_due_date, created_at, updated_at) VALUES (?, ?, ?, 'monthly', ?, 'active', ?, ?, ?, ?)",
            [$this->clientId, $this->parentProductId, 'Starter', 10.00, 'client.example.com', $nextDue, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
        );
    }

    private function request(array $body = [], array $query = [], string $method = 'GET'): \CodeVault\Request
    {
        return new \CodeVault\Request(
            $query,
            $body,
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/client/services'],
            [],
            [],
            ''
        );
    }

    public function test_addons_page_is_200_and_lists_available_addons(): void
    {
        $serviceId = $this->activeService();

        $response = $this->controller->addons($this->request(), ['id' => $serviceId]);

        $this->assertSame(200, $response->status());
        $body = $response->body();
        $this->assertStringContainsString('Extra IP', $body);
        $this->assertStringContainsString('Offsite Backup', $body);
    }

    public function test_addons_page_redirects_for_inactive_service(): void
    {
        $serviceId = $this->activeService();
        $this->db->update('UPDATE services SET status = ? WHERE id = ?', ['suspended', $serviceId]);

        $response = $this->controller->addons($this->request(), ['id' => $serviceId]);

        $this->assertSame(302, $response->status());
    }

    public function test_order_addon_creates_child_service_and_first_invoice(): void
    {
        $serviceId = $this->activeService();

        $response = $this->controller->orderAddon(
            $this->request(['addon_product_id' => $this->addonProductId], [], 'POST'),
            ['id' => $serviceId]
        );

        $this->assertSame(302, $response->status());

        $children = $this->services->addonsFor($serviceId);
        $this->assertCount(1, $children);
        $this->assertSame('Extra IP', $children[0]['product_name']);
        $this->assertSame($serviceId, (int) $children[0]['parent_id']);
        $this->assertSame('active', $children[0]['status']);

        // A first-period invoice was raised for the add-on's monthly price.
        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE service_id = ? ORDER BY id DESC LIMIT 1', [$children[0]['id']]);
        $this->assertNotNull($invoice);
        $this->assertSame(5.00, (float) $invoice['subtotal']);
    }

    public function test_order_addon_includes_setup_fee_on_first_invoice(): void
    {
        $serviceId = $this->activeService();
        $backupId = (int) $this->db->selectOne("SELECT id FROM products WHERE name = 'Offsite Backup'")['id'];

        $this->controller->orderAddon(
            $this->request(['addon_product_id' => $backupId], [], 'POST'),
            ['id' => $serviceId]
        );

        $child = $this->services->addonsFor($serviceId)[0];
        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE service_id = ? ORDER BY id DESC LIMIT 1', [$child['id']]);
        $this->assertSame(5.00, (float) $invoice['subtotal']); // 3.00 price + 2.00 setup
    }

    public function test_cannot_order_addon_not_configured_for_the_product(): void
    {
        $serviceId = $this->activeService();
        $unrelatedId = $this->insertProduct('Unrelated', 99.00, (new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $response = $this->controller->orderAddon(
            $this->request(['addon_product_id' => $unrelatedId], [], 'POST'),
            ['id' => $serviceId]
        );

        $this->assertSame(302, $response->status());
        $this->assertSame([], $this->services->addonsFor($serviceId));
    }

    public function test_cannot_order_the_same_addon_twice(): void
    {
        $serviceId = $this->activeService();

        $this->controller->orderAddon(
            $this->request(['addon_product_id' => $this->addonProductId], [], 'POST'),
            ['id' => $serviceId]
        );
        $this->controller->orderAddon(
            $this->request(['addon_product_id' => $this->addonProductId], [], 'POST'),
            ['id' => $serviceId]
        );

        $this->assertCount(1, $this->services->addonsFor($serviceId));
    }

    public function test_remove_addon_cancels_the_child_not_the_parent(): void
    {
        $serviceId = $this->activeService();
        $this->controller->orderAddon(
            $this->request(['addon_product_id' => $this->addonProductId], [], 'POST'),
            ['id' => $serviceId]
        );
        $child = $this->services->addonsFor($serviceId)[0];

        $response = $this->controller->removeAddon($this->request([], [], 'POST'), ['id' => $child['id']]);

        $this->assertSame(302, $response->status());
        $this->assertSame('cancelled', $this->db->selectOne('SELECT status FROM services WHERE id = ?', [$child['id']])['status']);
        $this->assertSame('active', $this->db->selectOne('SELECT status FROM services WHERE id = ?', [$serviceId])['status']);
    }

    public function test_client_index_excludes_child_addons(): void
    {
        $serviceId = $this->activeService();
        $this->controller->orderAddon(
            $this->request(['addon_product_id' => $this->addonProductId], [], 'POST'),
            ['id' => $serviceId]
        );

        $response = $this->controller->index($this->request());

        $this->assertSame(200, $response->status());
        $body = $response->body();
        $this->assertStringContainsString('Starter', $body);
        $this->assertStringNotContainsString('Extra IP', $body);
    }
}
