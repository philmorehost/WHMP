<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\CreateAccountJob;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\ServiceController;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Queue\Job;
use CodeVault\Queue\QueueInterface;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\RoleRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;

final class ServiceControllerPriceTest extends DatabaseTestCase
{
    private ServiceController $controller;
    private ServiceRepository $services;
    private ClientRepository $clients;
    private int $productId;
    private int $ngnId;
    private string $emptyConfigDir;

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        App::container()->instance(\CodeVault\Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $_SESSION = [];
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-service-price-test-' . uniqid();
        mkdir($this->emptyConfigDir);
        $session = new SessionManager(new Config($this->emptyConfigDir));

        $roles = new RoleRepository($this->db);
        $roleId = $roles->create('Owner', true, []);
        $adminId = (new AdminRepository($this->db))->create('ops', 'ops@example.test', 'secret123', 'Ops Admin', $roleId);
        $_SESSION['admin_id'] = $adminId;

        $this->clients = new ClientRepository($this->db);
        $this->ngnId = (new CurrencyRepository($this->db))->create('NGN', '₦', 1490.0000);

        $groups = new ProductGroupRepository($this->db);
        $groupId = $groups->create('Hosting', null);
        $products = new ProductRepository($this->db);
        $this->productId = $products->create(['product_group_id' => $groupId, 'name' => 'PMH2', 'stock_quantity' => 5]);
        (new ProductPricingRepository($this->db))->setPricing($this->productId, 'monthly', 0.00, 0.70);

        $this->services = new ServiceRepository($this->db);
        $this->controller = App::container()->make(ServiceController::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->emptyConfigDir);
        parent::tearDown();
    }

    /** @return array{int, int} client id and service id */
    private function makeService(float $amount, int $currencyId, int $index): array
    {
        $clientId = $this->clients->create([
            'email' => "service-price-{$index}@example.test",
            'password' => 'secret123',
            'first_name' => 'Price',
            'last_name' => 'Client',
            'currency_id' => $currencyId,
        ]);

        $serviceId = $this->services->create([
            'client_id' => $clientId,
            'product_id' => $this->productId,
            'product_name' => 'PMH2',
            'billing_cycle' => 'monthly',
            'amount' => $amount,
            'next_due_date' => '2026-09-01',
        ]);

        return [$clientId, $serviceId];
    }

    private function post(int $serviceId, array $inputs = []): void
    {
        $this->controller->updateDetails(
            new Request([], $inputs, ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $serviceId]
        );
    }

    public function test_set_package_price_converts_the_catalog_price_into_the_clients_currency(): void
    {
        [, $serviceId] = $this->makeService(1043.00, $this->ngnId, 1);

        $this->post($serviceId, ['set_package_price' => '1']);

        // Catalog price is $0.70 (base); the client is NGN at 1490, so the
        // stored recurring amount must be ₦1,043 — not the raw $0.70.
        $service = $this->services->find($serviceId);
        $this->assertSame(1043.00, (float) $service['amount']);
    }

    public function test_set_package_price_for_a_default_currency_client_keeps_the_catalog_price(): void
    {
        $defaultCurrency = $this->db->selectOne('SELECT id FROM currencies WHERE is_default = 1 LIMIT 1');
        [, $serviceId] = $this->makeService(0.70, (int) $defaultCurrency['id'], 2);

        $this->post($serviceId, ['set_package_price' => '1']);

        // Default client currency is USD (base), so the stored amount is the
        // catalog price unchanged.
        $service = $this->services->find($serviceId);
        $this->assertSame(0.70, (float) $service['amount']);
    }

    public function test_plain_details_update_leaves_the_amount_alone(): void
    {
        [, $serviceId] = $this->makeService(1043.00, $this->ngnId, 3);

        $this->post($serviceId, ['hostname' => 'srv1.example.com']);

        $service = $this->services->find($serviceId);
        $this->assertSame(1043.00, (float) $service['amount']);
    }

    public function test_admin_can_edit_the_renewal_date(): void
    {
        [, $serviceId] = $this->makeService(0.70, 1, 4);

        $this->post($serviceId, ['next_due_date' => '2026-12-01']);

        $service = $this->services->find($serviceId);
        $this->assertSame('2026-12-01', (string) $service['next_due_date']);
    }

    public function test_blank_renewal_date_leaves_the_existing_one_unchanged(): void
    {
        [, $serviceId] = $this->makeService(0.70, 1, 5);

        $this->post($serviceId, ['next_due_date' => '', 'hostname' => 'srv.example.com']);

        $service = $this->services->find($serviceId);
        $this->assertSame('2026-09-01', (string) $service['next_due_date']);
    }

    public function test_invalid_renewal_date_is_rejected_and_not_saved(): void
    {
        [, $serviceId] = $this->makeService(0.70, 1, 6);

        $this->post($serviceId, ['next_due_date' => 'not-a-date']);

        $service = $this->services->find($serviceId);
        $this->assertSame('2026-09-01', (string) $service['next_due_date']);
    }

    public function test_create_account_is_rejected_for_a_non_cpanel_service(): void
    {
        // A VPS-type product with no cPanel server anywhere — the guard must
        // refuse before any provisioning call is attempted.
        $groups = new ProductGroupRepository($this->db);
        $vpsGroupId = $groups->create('Servers', null);
        $products = new ProductRepository($this->db);
        $vpsProductId = $products->create([
            'product_group_id' => $vpsGroupId,
            'name' => 'VPS-1',
            'stock_quantity' => 5,
            'type' => 'vps',
        ]);

        $clientId = $this->clients->create([
            'email' => 'create-account-vps@example.test',
            'password' => 'secret123',
            'first_name' => 'VPS',
            'last_name' => 'Client',
            'currency_id' => 1,
        ]);

        $serviceId = $this->services->create([
            'client_id' => $clientId,
            'product_id' => $vpsProductId,
            'product_name' => 'VPS-1',
            'billing_cycle' => 'monthly',
            'amount' => 20.00,
            'next_due_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $response = $this->controller->createAccount(
            new Request([], [], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $serviceId]
        );

        // Redirected back with the guard error; the service is untouched.
        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('create_error', (string) ($response->headers()['Location'] ?? ''));
        $service = $this->services->find($serviceId);
        $this->assertSame('active', (string) $service['status']);
    }

    public function test_create_account_requires_login(): void
    {
        [, $serviceId] = $this->makeService(0.70, 1, 7);

        $_SESSION = [];

        $response = $this->controller->createAccount(
            new Request([], [], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $serviceId]
        );

        $this->assertSame(302, $response->status());
        $this->assertSame('/login', (string) ($response->headers()['Location'] ?? ''));
    }

    public function test_create_account_queues_a_background_job_for_a_cpanel_service(): void
    {
        // cPanel shared-hosting setup: a shared product in a group with a
        // cPanel server.
        $productGroupId = (new ProductGroupRepository($this->db))->create('Hosting', null);
        $serverGroupId = (new ServerGroupRepository($this->db))->create('Hosting');

        $servers = new ServerRepository($this->db);
        $serverId = $servers->create([
            'server_group_id' => $serverGroupId,
            'name' => 'WHM Primary',
            'hostname' => 'whm.example.test',
            'module_slug' => 'cpanel',
            'api_username' => 'root',
            'api_token' => str_repeat('A', 32),
            'api_port' => 2087,
            'use_ssl' => 1,
            'active' => 1,
        ]);

        $products = new ProductRepository($this->db);
        $sharedProductId = $products->create([
            'product_group_id' => $productGroupId,
            'server_group_id' => $serverGroupId,
            'name' => 'PMH2 Shared',
            'stock_quantity' => 5,
            'type' => 'shared',
            'whm_package_name' => 'cpanel_gold',
        ]);

        $clientId = $this->clients->create([
            'email' => 'create-account-cpanel@example.test',
            'password' => 'secret123',
            'first_name' => 'CPanel',
            'last_name' => 'Client',
            'currency_id' => 1,
        ]);

        $serviceId = $this->services->create([
            'client_id' => $clientId,
            'product_id' => $sharedProductId,
            'product_name' => 'PMH2 Shared',
            'billing_cycle' => 'monthly',
            'amount' => 10.00,
            'next_due_date' => '2026-09-01',
            'status' => 'active',
        ]);
        $this->services->assignServer($serviceId, $serverId, 'cvuser1');

        // Capture what gets pushed onto the queue instead of running it.
        $fakeQueue = new class () implements QueueInterface {
            /** @var array<int, Job> */
            public array $pushed = [];

            public function push(Job $job): void
            {
                $this->pushed[] = $job;
            }

            public function pop(string $queue = 'default'): ?Job
            {
                return null;
            }

            public function size(string $queue = 'default'): int
            {
                return 0;
            }
        };
        App::container()->instance(QueueInterface::class, $fakeQueue);

        $response = $this->controller->createAccount(
            new Request([], [], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $serviceId]
        );

        // The request returns immediately — the work is deferred, not run.
        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('create_queued=1', (string) ($response->headers()['Location'] ?? ''));

        $this->assertCount(1, $fakeQueue->pushed);
        $this->assertInstanceOf(CreateAccountJob::class, $fakeQueue->pushed[0]);
        $this->assertSame($serviceId, $fakeQueue->pushed[0]->serviceId);
    }
}
