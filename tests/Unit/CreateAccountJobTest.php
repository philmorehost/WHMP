<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\CreateAccountJob;
use CodeVault\Billing\ServiceRepository;
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
 * Create Account now runs in the background (CreateAccountJob) so the admin's
 * click returns immediately instead of blocking on WHM's createacct. These
 * tests drive the job's handle() directly — the exact code a queue worker
 * runs — and verify the admin is emailed the exact outcome (success message
 * or the precise failure reason).
 */
final class CreateAccountJobTest extends DatabaseTestCase
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
            'email' => 'createe@example.test',
            'password' => 'password123',
            'first_name' => 'Create',
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
     * ProvisioningService resolves our fake module for create().
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
            'name' => 'PMH2 Shared',
            'stock_quantity' => 5,
            'type' => 'shared',
        ]);

        $serviceId = $this->services->create([
            'client_id' => $this->clientId,
            'product_id' => $productId,
            'product_name' => 'PMH2 Shared',
            'billing_cycle' => 'monthly',
            'amount' => 9.99,
            'status' => 'active',
            'next_due_date' => '2026-09-01',
        ]);
        $this->services->assignServer($serviceId, $serverId, 'cvuser1');

        return $serviceId;
    }

    public function test_job_is_plain_serializable_payload(): void
    {
        $job = new CreateAccountJob(7, 3, '203.0.113.10');

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(CreateAccountJob::class, $restored);
        $this->assertSame(7, $restored->serviceId);
        $this->assertSame(3, $restored->adminId);
        $this->assertSame('203.0.113.10', $restored->adminIp);
        $this->assertSame('default', $job->queue());
    }

    public function test_handle_notifies_admin_when_account_is_created_successfully(): void
    {
        $serviceId = $this->insertService();
        $this->module->createResult = ['success' => true, 'message' => 'Account Creation Ok'];

        (new CreateAccountJob($serviceId, $this->adminId, '203.0.113.10'))->handle();

        // The real ProvisioningService drove the module's create().
        $this->assertCount(1, $this->module->createCalls);

        // Activity logged with the success result.
        $log = $this->db->selectOne("SELECT * FROM activity_log WHERE action = 'service.create_account' AND subject_id = ?", [$serviceId]);
        $this->assertNotNull($log, 'handle() must log service.create_account');
        $this->assertStringContainsString('Account Creation Ok', $log['description']);

        // Admin emailed via the success template.
        $emails = $this->db->select("SELECT * FROM email_log WHERE template_key = 'service_account_created'");
        $this->assertCount(1, $emails);
        $this->assertSame($this->adminEmail, $emails[0]['to_email']);

        $mail = $this->sentTo('Account Created');
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('created successfully', $mail[0]['html']);
        $this->assertStringContainsString('Account Creation Ok', $mail[0]['html']);
    }

    public function test_handle_notifies_admin_with_the_exact_reason_when_creation_fails(): void
    {
        $serviceId = $this->insertService();
        $this->module->createResult = ['success' => false, 'message' => 'WHM refused: package missing'];

        (new CreateAccountJob($serviceId, $this->adminId, '203.0.113.10'))->handle();

        // Activity logged as FAILED with the reason.
        $log = $this->db->selectOne("SELECT * FROM activity_log WHERE action = 'service.create_account' AND subject_id = ?", [$serviceId]);
        $this->assertNotNull($log);
        $this->assertStringContainsString('FAILED', $log['description']);
        $this->assertStringContainsString('WHM refused: package missing', $log['description']);

        // Admin emailed via the failure template carrying the exact reason.
        $emails = $this->db->select("SELECT * FROM email_log WHERE template_key = 'service_account_create_failed'");
        $this->assertCount(1, $emails);

        $mail = $this->sentTo('Account Creation Failed');
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('WHM refused: package missing', $mail[0]['html']);
    }
}
