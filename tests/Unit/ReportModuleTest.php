<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ReportModule;
use CodeVault\Modules\ReportModuleRepository;
use CodeVault\Modules\ReportModuleService;
use CodeVault\Reports\ServiceChurnReport;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class ReportModuleTest extends DatabaseTestCase
{
    private ReportModuleRepository $repository;
    private ClientRepository $clients;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->repository = new ReportModuleRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->clientId = $this->clients->create([
            'email' => 'churn@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Churn',
            'last_name' => 'Test',
            'phone' => '+1 555 0100',
        ]);
    }

    // --- ReportModuleRepository ----------------------------------------------

    public function test_a_new_slug_is_inactive_by_default(): void
    {
        $this->assertFalse($this->repository->isActive('never-seen'));
    }

    public function test_activate_then_deactivate_round_trips_correctly(): void
    {
        $this->repository->activate('demo-report');
        $this->assertTrue($this->repository->isActive('demo-report'));

        $row = $this->repository->find('demo-report');
        $this->assertNotNull($row['activated_at']);

        $this->repository->deactivate('demo-report');
        $this->assertFalse($this->repository->isActive('demo-report'));
    }

    // --- ReportModuleService --------------------------------------------------

    public function test_activate_rejects_an_unknown_slug(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $service = new ReportModuleService($modules, $this->repository);

        $result = $service->activate('does-not-exist');

        $this->assertFalse($result['success']);
    }

    public function test_catalog_reflects_activation_state(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(ReportModule::class, 'service-churn', new ServiceChurnReport($this->db));

        $service = new ReportModuleService($modules, $this->repository);

        $catalog = $service->catalog();
        $this->assertCount(1, $catalog);
        $this->assertFalse($catalog[0]['active']);

        $service->activate('service-churn');
        $catalog = $service->catalog();
        $this->assertTrue($catalog[0]['active']);
    }

    public function test_run_rejects_an_unknown_slug(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $service = new ReportModuleService($modules, $this->repository);

        $result = $service->run('does-not-exist', []);

        $this->assertFalse($result['success']);
    }

    public function test_run_rejects_a_deactivated_report_even_though_it_is_registered(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(ReportModule::class, 'service-churn', new ServiceChurnReport($this->db));
        $service = new ReportModuleService($modules, $this->repository);

        // Never activated.
        $result = $service->run('service-churn', []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not active', $result['message']);
    }

    public function test_run_returns_generated_data_for_an_active_report(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(ReportModule::class, 'service-churn', new ServiceChurnReport($this->db));
        $service = new ReportModuleService($modules, $this->repository);
        $service->activate('service-churn');

        $result = $service->run('service-churn', []);

        $this->assertTrue($result['success']);
        $this->assertSame(['Product', 'Cancelled', 'Terminated', 'Lost Monthly Recurring Revenue'], $result['columns']);
        $this->assertSame([], $result['rows']);
    }

    // --- ServiceChurnReport ----------------------------------------------------

    public function test_generate_ignores_active_services(): void
    {
        $productId = $this->insertProduct();
        $this->insertService($productId, 'Starter', 'active', 'monthly', 9.99, (new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $report = new ServiceChurnReport($this->db);
        $result = $report->generate([]);

        $this->assertSame([], $result['rows']);
    }

    public function test_generate_groups_by_product_and_normalizes_billing_cycles_to_monthly(): void
    {
        $productId = $this->insertProduct('Starter VPS');
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // Two monthly cancellations at $10/mo -> $20 lost MRR.
        $this->insertService($productId, 'Starter VPS', 'cancelled', 'monthly', 10.00, $now);
        $this->insertService($productId, 'Starter VPS', 'cancelled', 'monthly', 10.00, $now);
        // One annual termination at $120/yr -> $10/mo lost MRR.
        $this->insertService($productId, 'Starter VPS', 'terminated', 'annually', 120.00, $now);

        $report = new ServiceChurnReport($this->db);
        $result = $report->generate([]);

        $this->assertCount(1, $result['rows']);
        [$product, $cancelled, $terminated, $lostMrr] = $result['rows'][0];
        $this->assertSame('Starter VPS', $product);
        $this->assertSame(2, $cancelled);
        $this->assertSame(1, $terminated);
        $this->assertSame(30.0, $lostMrr);
    }

    public function test_generate_excludes_one_time_services_from_lost_mrr_but_still_counts_them(): void
    {
        $productId = $this->insertProduct();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->insertService($productId, 'Starter', 'cancelled', 'one_time', 50.00, $now);

        $report = new ServiceChurnReport($this->db);
        $result = $report->generate([]);

        $this->assertCount(1, $result['rows']);
        [, $cancelled, , $lostMrr] = $result['rows'][0];
        $this->assertSame(1, $cancelled);
        $this->assertSame(0.0, $lostMrr);
    }

    public function test_generate_respects_the_date_range_filter(): void
    {
        $productId = $this->insertProduct();

        $this->insertService($productId, 'Starter', 'cancelled', 'monthly', 10.00, '2020-01-15 00:00:00');
        $this->insertService($productId, 'Starter', 'cancelled', 'monthly', 10.00, (new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $report = new ServiceChurnReport($this->db);
        $result = $report->generate(['start_date' => '2020-01-01', 'end_date' => '2020-01-31']);

        $this->assertCount(1, $result['rows']);
        [, $cancelled] = $result['rows'][0];
        $this->assertSame(1, $cancelled);
    }

    private function insertProduct(string $name = 'Starter'): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $groupId = (int) $this->db->insert('INSERT INTO product_groups (name, created_at, updated_at) VALUES (?, ?, ?)', ['Hosting', $now, $now]);

        return (int) $this->db->insert('INSERT INTO products (product_group_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)', [$groupId, $name, $now, $now]);
    }

    private function insertService(int $productId, string $productName, string $status, string $billingCycle, float $amount, string $updatedAt): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $productId, $productName, $billingCycle, $amount, $status, $now, $now, $updatedAt]
        );
    }
}
