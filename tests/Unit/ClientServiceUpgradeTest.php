<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ClientServiceController;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\ProrationService;
use CodeVault\Billing\ServiceRepository;
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
 * Client-side upgrade/downgrade (blueprint §4.4 "Upgrade/Downgrade
 * engine"). The proration math itself is ProrationService's territory and
 * has its own suite; these tests lock in the client-facing guard rails:
 * only active services offer the form, candidates are scoped to the
 * service's own product group with a price for the current cycle, and a
 * client can't submit an arbitrary product_id.
 */
final class ClientServiceUpgradeTest extends DatabaseTestCase
{
    private ClientServiceController $controller;
    private ServiceRepository $services;
    private int $clientId;
    private int $groupId;
    private int $currentProductId;
    private int $targetProductId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $clients = new ClientRepository($this->db);
        $this->clientId = $clients->create([
            'email' => 'upgrade-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Upgrade',
            'last_name' => 'Tester',
        ]);

        // Product group with two active plans, both priced monthly.
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->groupId = (int) $this->db->insert(
            'INSERT INTO product_groups (name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['Shared Hosting', 0, $now, $now]
        );
        $this->currentProductId = $this->insertProduct('Starter', $now);
        $this->targetProductId = $this->insertProduct('Growth', $now);
        // A product in a DIFFERENT group — must never be offered as a target.
        $otherGroupId = (int) $this->db->insert(
            'INSERT INTO product_groups (name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['VPS', 1, $now, $now]
        );
        $vpsProductId = (int) $this->db->insert(
            'INSERT INTO products (product_group_id, name, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$otherGroupId, 'VPS 1', 'active', 0, $now, $now]
        );
        $this->db->insert(
            'INSERT INTO product_pricing (product_id, billing_cycle, setup_fee, price) VALUES (?, ?, ?, ?)',
            [$vpsProductId, 'monthly', 0, 20.00]
        );

        $this->services = new ServiceRepository($this->db);

        $configDir = sys_get_temp_dir() . '/codevault-client-upgrade-' . uniqid();
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
            $this->proration()
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

    private function insertProduct(string $name, string $now): int
    {
        $productId = (int) $this->db->insert(
            'INSERT INTO products (product_group_id, name, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$this->groupId, $name, 'active', 0, $now, $now]
        );

        $this->db->insert(
            'INSERT INTO product_pricing (product_id, billing_cycle, setup_fee, price) VALUES (?, ?, ?, ?)',
            [$productId, 'monthly', 0, 10.00]
        );

        return $productId;
    }

    private function activeService(int $productId, float $amount = 10.00): int
    {
        $nextDue = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
        return (int) $this->db->insert(
            "INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, domain, next_due_date, created_at, updated_at) VALUES (?, ?, ?, 'monthly', ?, 'active', ?, ?, ?, ?)",
            [$this->clientId, $productId, 'Starter', $amount, 'client.example.com', $nextDue, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
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

    public function test_upgrade_form_is_200_for_an_active_service(): void
    {
        $serviceId = $this->activeService($this->currentProductId);

        $response = $this->controller->upgradeForm($this->request(), ['id' => $serviceId]);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Growth', $response->body());
        // The VPS plan in the other group must not appear.
        $this->assertStringNotContainsString('VPS 1', $response->body());
    }

    public function test_upgrade_form_rejects_inactive_service(): void
    {
        $serviceId = $this->activeService($this->currentProductId);
        $this->services->updateStatus($serviceId, 'suspended');

        $response = $this->controller->upgradeForm($this->request(), ['id' => $serviceId]);

        $this->assertSame(302, $response->status());
    }

    public function test_upgrade_rejects_a_product_outside_the_group(): void
    {
        $serviceId = $this->activeService($this->currentProductId);
        $otherProductId = (int) $this->db->selectOne(
            "SELECT id FROM products WHERE name = 'VPS 1'"
        )['id'];

        $response = $this->controller->upgrade(
            $this->request(['product_id' => $otherProductId, 'proration_mode' => 'none'], [], 'POST'),
            ['id' => $serviceId]
        );

        $this->assertSame(302, $response->status());
        // Service unchanged.
        $service = $this->services->find($serviceId);
        $this->assertSame($this->currentProductId, (int) $service['product_id']);
    }

    public function test_upgrade_changes_the_plan_to_a_same_group_product(): void
    {
        $serviceId = $this->activeService($this->currentProductId);

        $response = $this->controller->upgrade(
            $this->request(['product_id' => $this->targetProductId, 'proration_mode' => 'none'], [], 'POST'),
            ['id' => $serviceId]
        );

        $this->assertSame(302, $response->status());

        $service = $this->services->find($serviceId);
        $this->assertSame($this->targetProductId, (int) $service['product_id']);
        $this->assertSame('Growth', $service['product_name']);
    }
}
