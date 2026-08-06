<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\ServiceController;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
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
}
