<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\WidgetModule;
use CodeVault\Modules\WidgetModuleRepository;
use CodeVault\Modules\WidgetModuleService;
use CodeVault\Reports\TopClientsWidget;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class WidgetModuleTest extends DatabaseTestCase
{
    private WidgetModuleRepository $repository;
    private ClientRepository $clients;
    private InvoiceRepository $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->repository = new WidgetModuleRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
    }

    // --- WidgetModuleRepository ---------------------------------------------

    public function test_a_new_slug_is_inactive_by_default(): void
    {
        $this->assertFalse($this->repository->isActive('never-seen'));
    }

    public function test_activate_then_deactivate_round_trips_correctly(): void
    {
        $this->repository->activate('demo-widget');
        $this->assertTrue($this->repository->isActive('demo-widget'));

        $row = $this->repository->find('demo-widget');
        $this->assertNotNull($row['activated_at']);

        $this->repository->deactivate('demo-widget');
        $this->assertFalse($this->repository->isActive('demo-widget'));
    }

    public function test_config_round_trips_as_json(): void
    {
        $this->repository->setConfig('demo-widget', ['limit' => 10]);

        $this->assertSame(['limit' => 10], $this->repository->getConfig('demo-widget'));
    }

    // --- WidgetModuleService -------------------------------------------------

    public function test_activate_rejects_an_unknown_slug(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $service = new WidgetModuleService($modules, $this->repository);

        $result = $service->activate('does-not-exist');

        $this->assertFalse($result['success']);
    }

    public function test_catalog_reflects_activation_state(): void
    {
        $modules = new ModuleManager(new HookDispatcher());
        $widget = new TopClientsWidget($this->invoices);
        $modules->register(WidgetModule::class, 'top-clients', $widget);

        $service = new WidgetModuleService($modules, $this->repository);

        $catalog = $service->catalog();
        $this->assertCount(1, $catalog);
        $this->assertFalse($catalog[0]['active']);

        $service->activate('top-clients');
        $catalog = $service->catalog();
        $this->assertTrue($catalog[0]['active']);
    }

    public function test_active_widgets_for_placement_excludes_inactive_and_wrong_placement(): void
    {
        $modules = new ModuleManager(new HookDispatcher());

        $dashboardWidget = new TopClientsWidget($this->invoices);
        $otherPlacementWidget = new class implements WidgetModule {
            public function metadata(): array
            {
                return ['name' => 'Elsewhere', 'description' => '', 'version' => '1.0.0', 'author' => 'Test'];
            }

            public function configOptions(): array
            {
                return [];
            }

            public function placement(): string
            {
                return 'client-portal';
            }

            public function render(): string
            {
                return 'elsewhere';
            }
        };

        $modules->register(WidgetModule::class, 'top-clients', $dashboardWidget);
        $modules->register(WidgetModule::class, 'elsewhere', $otherPlacementWidget);

        $service = new WidgetModuleService($modules, $this->repository);

        // Neither activated yet — placement query returns nothing.
        $this->assertSame([], $service->activeWidgetsForPlacement('dashboard'));

        $service->activate('top-clients');
        $service->activate('elsewhere');

        $dashboardWidgets = $service->activeWidgetsForPlacement('dashboard');
        $this->assertCount(1, $dashboardWidgets);
        $this->assertSame($dashboardWidget, $dashboardWidgets[0]);
    }

    // --- TopClientsWidget ------------------------------------------------------

    public function test_render_shows_placeholder_when_no_paid_invoices_exist(): void
    {
        $widget = new TopClientsWidget($this->invoices);

        $html = $widget->render();

        $this->assertStringContainsString('No paid invoices yet', $html);
    }

    public function test_render_ranks_clients_by_paid_total_descending(): void
    {
        $bigSpender = $this->makeClientWithPaidInvoice('big@example.test', 500.00);
        $smallSpender = $this->makeClientWithPaidInvoice('small@example.test', 25.00);

        $widget = new TopClientsWidget($this->invoices);
        $html = $widget->render();

        $bigPos = strpos($html, 'big@example.test');
        $smallPos = strpos($html, 'small@example.test');

        $this->assertNotFalse($bigPos);
        $this->assertNotFalse($smallPos);
        $this->assertLessThan($smallPos, $bigPos);
        $this->assertStringContainsString('500.00', $html);
        $this->assertStringContainsString(number_format(25.00, 2), $html);
        $this->assertStringContainsString('/admin/clients/' . $bigSpender, $html);
    }

    public function test_render_ignores_unpaid_invoices(): void
    {
        $clientId = $this->clients->create([
            'email' => 'unpaid@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Un',
            'last_name' => 'Paid',
            'phone' => '+1 555 0100',
        ]);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->insert(
            'INSERT INTO invoices (client_id, status, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$clientId, 'unpaid', 100.00, $now, $now, $now]
        );

        $widget = new TopClientsWidget($this->invoices);
        $html = $widget->render();

        $this->assertStringContainsString('No paid invoices yet', $html);
    }

    private function makeClientWithPaidInvoice(string $email, float $total): int
    {
        $clientId = $this->clients->create([
            'email' => $email,
            'password' => 'correct-horse-battery',
            'first_name' => 'Test',
            'last_name' => 'Client',
            'phone' => '+1 555 0100',
        ]);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->insert(
            'INSERT INTO invoices (client_id, status, total, due_date, paid_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'paid', $total, $now, $now, $now, $now]
        );

        return $clientId;
    }
}
