<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CancellationRequestRepository;
use CodeVault\Billing\CancellationCronJob;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Provisioning\LocalProvisioningModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Activity\ActivityLogger;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class ServiceCancellationTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private ServerRepository $servers;
    private ServerGroupRepository $serverGroups;
    private ProductRepository $products;
    private ProvisioningService $provisioning;
    private CancellationRequestRepository $cancellations;
    private InvoiceRepository $invoices;
    private ActivityLogger $activity;
    private int $clientId;
    private int $productId;
    private int $serverId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->services = new ServiceRepository($this->db);
        $this->servers = new ServerRepository($this->db);
        $this->serverGroups = new ServerGroupRepository($this->db);
        $this->products = new ProductRepository($this->db);
        $this->cancellations = new CancellationRequestRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->activity = new ActivityLogger($this->db);

        $localModule = new LocalProvisioningModule(sys_get_temp_dir() . '/codevault-cancel-' . uniqid());
        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $modules->register(ProvisioningModule::class, 'local', $localModule);

        $this->provisioning = new ProvisioningService($this->services, $this->products, $this->servers, $modules, $hooks);

        $clients = new ClientRepository($this->db);
        $this->clientId = $clients->create([
            'email' => 'canceltest@example.test',
            'password' => 'secret123',
            'first_name' => 'Cancel',
            'last_name' => 'Test',
        ]);

        $productGroups = new ProductGroupRepository($this->db);
        $groupId = $productGroups->create(['name' => 'Test Group']);

        $sgId = $this->serverGroups->create(['name' => 'Group 1']);
        $this->serverId = $this->servers->create([
            'server_group_id' => $sgId,
            'name' => 'Server 1',
            'hostname' => 'localhost',
            'module_slug' => 'local',
            'api_username' => 'admin',
            'api_token' => 'token',
            'use_ssl' => 0,
            'active' => 1,
        ]);

        $this->productId = $this->products->create([
            'product_group_id' => $groupId,
            'server_group_id' => $sgId,
            'name' => 'VPS Plan A',
        ]);
    }

    public function test_cancel_unpaid_for_service(): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $serviceId = (int) $this->db->insert(
            'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $this->productId, 'VPS Plan A', 'monthly', 10.00, 'active', '2026-08-22', $now, $now]
        );

        $inv1 = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, service_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $serviceId, 'unpaid', 10.00, 0.00, 10.00, '2026-08-22', $now, $now]
        );

        $inv2 = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, service_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $serviceId, 'paid', 10.00, 0.00, 10.00, '2026-07-22', $now, $now]
        );

        $this->invoices->cancelUnpaidForService($serviceId);

        $this->assertSame('cancelled', $this->invoices->find($inv1)['status']);
        $this->assertSame('paid', $this->invoices->find($inv2)['status']);
    }

    public function test_cron_processes_due_cancellation_requests(): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $today = (new DateTimeImmutable())->format('Y-m-d');
        
        $serviceId = (int) $this->db->insert(
            'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, server_id, username, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $this->productId, 'VPS Plan A', 'monthly', 10.00, 'active', $today, $this->serverId, 'user123', $now, $now]
        );

        $requestId = $this->cancellations->createRequest($serviceId, 'end_of_period', 'No longer need it');

        $cron = new CancellationCronJob($this->cancellations, $this->services, $this->provisioning, $this->activity);
        $cron->handle();

        $service = $this->services->find($serviceId);
        $this->assertSame('cancelled', $service['status']);

        $request = $this->cancellations->find($requestId);
        $this->assertSame('processed', $request['status']);
    }
}
