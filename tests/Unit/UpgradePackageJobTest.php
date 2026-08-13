<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\UpgradePackageJob;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Mail\Mailer;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Fixtures\FakeProvisioningModule;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Package-plan upgrades now run the WHM changepackage in the background
 * (UpgradePackageJob) so the admin's browser isn't blocked, and report the
 * outcome to every admin by email. These tests drive handle() directly and
 * verify the success/failure email and activity log.
 */
final class UpgradePackageJobTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private int $clientId;
    private int $adminId;
    private string $adminEmail = 'ops@example.test';
    private FakeProvisioningModule $module;
    /** @var array<int, array{to: string, subject: string, html: string}> */
    private array $sentMails = [];

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        $container = App::container();
        $container->instance(Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->services = new ServiceRepository($this->db);
        $container->instance(ServiceRepository::class, $this->services);
        $container->instance(ProductRepository::class, new ProductRepository($this->db));
        $container->instance(ServerRepository::class, new ServerRepository($this->db));

        // Swap the real cPanel module for a scriptable double so the real
        // ProvisioningService resolves it without touching the network.
        $this->module = new FakeProvisioningModule();
        $container->make(ModuleManager::class)->register(ProvisioningModule::class, 'cpanel', $this->module);

        $this->sentMails = [];
        $container->instance(Mailer::class, new class ($this->sentMails) implements Mailer {
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
            'email' => 'upgradee@example.test',
            'password' => 'password123',
            'first_name' => 'Upgrade',
            'last_name' => 'Me',
        ]);

        $this->adminId = (new AdminRepository($this->db))->create('ops', $this->adminEmail, 'secret123', 'Ops Admin', null);
    }

    /** @return array<int, array<string, mixed>> */
    private function sentTo(string $subjectFragment): array
    {
        return array_values(array_filter($this->sentMails, static fn (array $m) => str_contains($m['subject'], $subjectFragment)));
    }

    /**
     * A service provisioned onto a cPanel server, so the real
     * ProvisioningService resolves our fake module for changePackage().
     */
    private function insertService(): int
    {
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
        $productId = $products->create([
            'product_group_id' => $productGroupId,
            'server_group_id' => $serverGroupId,
            'name' => 'PMH2 Gold',
            'stock_quantity' => 5,
            'type' => 'shared',
        ]);

        $serviceId = $this->services->create([
            'client_id' => $this->clientId,
            'product_id' => $productId,
            'product_name' => 'PMH2 Gold',
            'billing_cycle' => 'monthly',
            'amount' => 19.99,
            'status' => 'active',
            'next_due_date' => '2026-09-01',
        ]);
        $this->services->assignServer($serviceId, $serverId, 'cvuser1');

        return $serviceId;
    }

    public function test_job_is_plain_serializable_payload(): void
    {
        $job = new UpgradePackageJob(7, 'cpanel_gold', 3, '203.0.113.10');

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(UpgradePackageJob::class, $restored);
        $this->assertSame(7, $restored->serviceId);
        $this->assertSame('cpanel_gold', $restored->package);
        $this->assertSame(3, $restored->adminId);
        $this->assertSame('203.0.113.10', $restored->adminIp);
        $this->assertSame('default', $job->queue());
    }

    public function test_handle_notifies_admin_when_the_package_change_succeeds(): void
    {
        $serviceId = $this->insertService();
        $this->module->changePackageResult = ['success' => true, 'message' => 'changepackage ok'];

        (new UpgradePackageJob($serviceId, 'cpanel_gold', $this->adminId, '203.0.113.10'))->handle();

        $this->assertCount(1, $this->module->changePackageCalls);
        $this->assertSame('cpanel_gold', $this->module->changePackageCalls[0]['package'] ?? null);

        $log = $this->db->selectOne("SELECT * FROM activity_log WHERE action = 'service.package_changed' AND subject_id = ?", [$serviceId]);
        $this->assertNotNull($log, 'handle() must log service.package_changed');
        $this->assertStringContainsString('cpanel_gold', $log['description']);

        $emails = $this->db->select("SELECT * FROM email_log WHERE template_key = 'service_package_upgraded'");
        $this->assertCount(1, $emails);
        $this->assertSame($this->adminEmail, $emails[0]['to_email']);

        $mail = $this->sentTo('Package Upgraded');
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('cpanel_gold', $mail[0]['html']);
    }

    public function test_handle_notifies_admin_with_the_exact_reason_when_the_package_change_fails(): void
    {
        $serviceId = $this->insertService();
        $this->module->changePackageResult = ['success' => false, 'message' => 'changepackage: package does not exist'];

        (new UpgradePackageJob($serviceId, 'cpanel_gold', $this->adminId, '203.0.113.10'))->handle();

        $log = $this->db->selectOne("SELECT * FROM activity_log WHERE action = 'service.package_changed' AND subject_id = ?", [$serviceId]);
        $this->assertStringContainsString('FAILED', $log['description']);

        $emails = $this->db->select("SELECT * FROM email_log WHERE template_key = 'service_package_upgrade_failed'");
        $this->assertCount(1, $emails);

        $mail = $this->sentTo('Package Upgrade Failed');
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('package does not exist', $mail[0]['html']);
    }
}
