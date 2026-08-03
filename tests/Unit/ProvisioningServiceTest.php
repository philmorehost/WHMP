<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Provisioning\LocalProvisioningModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class ProvisioningServiceTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private ServerRepository $servers;
    private ServerGroupRepository $serverGroups;
    private ProductRepository $products;
    private ProvisioningService $provisioning;
    private string $localStorageDir;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->services = new ServiceRepository($this->db);
        $this->servers = new ServerRepository($this->db);
        $this->serverGroups = new ServerGroupRepository($this->db);
        $this->products = new ProductRepository($this->db);

        $this->localStorageDir = sys_get_temp_dir() . '/codevault-orchestration-' . uniqid();
        $localModule = new LocalProvisioningModule($this->localStorageDir);

        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $modules->register(ProvisioningModule::class, 'local', $localModule);

        $this->provisioning = new ProvisioningService($this->services, $this->products, $this->servers, $modules, $hooks);

        $clients = new ClientRepository($this->db);
        $this->clientId = $clients->create([
            'email' => 'provtest@example.test',
            'password' => 'secret123',
            'first_name' => 'Prov',
            'last_name' => 'Test',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->localStorageDir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->localStorageDir);
        parent::tearDown();
    }

    private function createProductInGroup(?int $serverGroupId): int
    {
        $groups = new ProductGroupRepository($this->db);
        $groupId = $groups->create('Hosting', null);

        return $this->products->create([
            'product_group_id' => $groupId,
            'server_group_id' => $serverGroupId,
            'name' => 'Starter',
        ]);
    }

    private function createServiceForProduct(int $productId): int
    {
        return $this->services->create([
            'client_id' => $this->clientId,
            'product_id' => $productId,
            'product_name' => 'Starter',
            'billing_cycle' => 'monthly',
            'amount' => 9.99,
            'status' => 'pending',
            'next_due_date' => (new DateTimeImmutable('+1 month'))->format('Y-m-d'),
        ]);
    }

    public function test_provisioning_a_product_with_no_server_group_just_activates_locally(): void
    {
        $productId = $this->createProductInGroup(null);
        $serviceId = $this->createServiceForProduct($productId);

        $result = $this->provisioning->provision($serviceId);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('active', $this->services->find($serviceId)['status']);
    }

    public function test_provisioning_with_no_active_server_records_a_failure(): void
    {
        $groupId = $this->serverGroups->create('Empty Group');
        $productId = $this->createProductInGroup($groupId);
        $serviceId = $this->createServiceForProduct($productId);

        $result = $this->provisioning->provision($serviceId);

        $this->assertFalse($result['success']);
        $service = $this->services->find($serviceId);
        $this->assertSame('pending', $service['status'], 'a failed provision must not silently activate the service');
        $this->assertNotNull($service['provisioning_error']);
    }

    public function test_successful_provisioning_activates_the_service_and_assigns_server(): void
    {
        $groupId = $this->serverGroups->create('Group A');
        $serverId = $this->servers->create([
            'server_group_id' => $groupId,
            'name' => 'srv1',
            'hostname' => 'srv1.local',
            'module_slug' => 'local',
        ]);
        $productId = $this->createProductInGroup($groupId);
        $serviceId = $this->createServiceForProduct($productId);

        $result = $this->provisioning->provision($serviceId);

        $this->assertTrue($result['success']);
        $service = $this->services->find($serviceId);
        $this->assertSame('active', $service['status']);
        $this->assertSame($serverId, (int) $service['server_id']);
        $this->assertNotNull($service['username']);
        $this->assertNull($service['provisioning_error']);
    }

    public function test_least_loaded_server_is_chosen_for_provisioning(): void
    {
        $groupId = $this->serverGroups->create('Group B');
        $busyServerId = $this->servers->create(['server_group_id' => $groupId, 'name' => 'busy', 'hostname' => 'busy.local', 'module_slug' => 'local']);
        $quietServerId = $this->servers->create(['server_group_id' => $groupId, 'name' => 'quiet', 'hostname' => 'quiet.local', 'module_slug' => 'local']);

        $productId = $this->createProductInGroup($groupId);

        // Load up the "busy" server with an already-active service first.
        $firstServiceId = $this->createServiceForProduct($productId);
        $this->services->assignServer($firstServiceId, $busyServerId, 'existing-user');
        $this->services->activate($firstServiceId);

        $newServiceId = $this->createServiceForProduct($productId);
        $result = $this->provisioning->provision($newServiceId);

        $this->assertTrue($result['success']);
        $this->assertSame($quietServerId, (int) $this->services->find($newServiceId)['server_id']);
    }

    public function test_suspend_terminate_lifecycle_updates_status_and_fires_hooks(): void
    {
        $groupId = $this->serverGroups->create('Group C');
        $this->servers->create(['server_group_id' => $groupId, 'name' => 'srv', 'hostname' => 'srv.local', 'module_slug' => 'local']);
        $productId = $this->createProductInGroup($groupId);
        $serviceId = $this->createServiceForProduct($productId);
        $this->provisioning->provision($serviceId);

        $fired = [];
        $hooks = new HookDispatcher();
        $hooks->register(HookPoints::SERVICE_STATUS_CHANGED, function (array $p) use (&$fired) {
            $fired[] = $p;
        });
        $modules = new ModuleManager($hooks);
        $modules->register(ProvisioningModule::class, 'local', new LocalProvisioningModule($this->localStorageDir));
        $provisioning = new ProvisioningService($this->services, $this->products, $this->servers, $modules, $hooks);

        $suspendResult = $provisioning->suspend($serviceId);
        $this->assertTrue($suspendResult['success']);
        $this->assertSame('suspended', $this->services->find($serviceId)['status']);

        $terminateResult = $provisioning->terminate($serviceId);
        $this->assertTrue($terminateResult['success']);
        $this->assertSame('terminated', $this->services->find($serviceId)['status']);

        $this->assertCount(2, $fired);
    }

    public function test_cannot_suspend_a_service_that_was_never_provisioned(): void
    {
        $productId = $this->createProductInGroup(null);
        $serviceId = $this->createServiceForProduct($productId);

        $result = $this->provisioning->suspend($serviceId);

        $this->assertFalse($result['success']);
    }

    /**
     * The client service page offers power, snapshot and slice controls only
     * some modules can honour. Every one of these must report failure when
     * the module has no such method — the bug these guard against is the
     * controller reporting "action issued successfully" for an action no
     * module ever performed. LocalProvisioningModule implements none of
     * them, so it is the right stand-in for "module can't do this".
     *
     * @return array<string, array{0: string}>
     */
    public static function unsupportedActionProvider(): array
    {
        return [
            'power' => ['power'],
            'createBackup' => ['createBackup'],
            'listBackups' => ['listBackups'],
            'sliceOptions' => ['sliceOptions'],
            'remoteInfo' => ['remoteInfo'],
            'reverseDnsEntries' => ['reverseDnsEntries'],
            'osTemplates' => ['osTemplates'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedActionProvider')]
    public function test_actions_a_module_does_not_implement_report_failure(string $method): void
    {
        $serviceId = $this->provisionOntoLocalServer();

        $result = $method === 'power'
            ? $this->provisioning->power($serviceId, 'restart')
            : $this->provisioning->{$method}($serviceId);

        $this->assertFalse($result['success'], "{$method}() must not report success on a module that cannot perform it");
        $this->assertNotSame('', trim((string) $result['message']));
    }

    public function test_power_does_not_change_the_local_service_status(): void
    {
        $serviceId = $this->provisionOntoLocalServer();
        $this->assertSame('active', $this->services->find($serviceId)['status']);

        $this->provisioning->power($serviceId, 'stop');

        // A client rebooting or halting their own VPS is not a billing
        // transition. Recording it as one would let a reboot mark an active
        // service suspended.
        $this->assertSame('active', $this->services->find($serviceId)['status']);
    }

    public function test_power_on_an_unprovisioned_service_reports_failure(): void
    {
        $productId = $this->createProductInGroup(null);
        $serviceId = $this->createServiceForProduct($productId);

        $result = $this->provisioning->power($serviceId, 'restart');

        $this->assertFalse($result['success']);
    }

    /**
     * Puts a service into the same state a successful provision leaves it —
     * assigned to a server, with a username, active. Done through the
     * repository rather than provision(), because provision() reaches for
     * App::container() to generate a username and that container is not
     * booted in this suite (the three pre-existing provision() tests in this
     * file fail on it for the same reason).
     */
    private function provisionOntoLocalServer(): int
    {
        $groupId = $this->serverGroups->create('Optional-action group');
        $serverId = $this->servers->create([
            'server_group_id' => $groupId,
            'name' => 'local-1',
            'hostname' => 'local-1.test',
            'module_slug' => 'local',
        ]);

        $productId = $this->createProductInGroup($groupId);
        $serviceId = $this->createServiceForProduct($productId);

        $this->services->assignServer($serviceId, $serverId, 'localuser');
        $this->services->activate($serviceId);

        return $serviceId;
    }
}
