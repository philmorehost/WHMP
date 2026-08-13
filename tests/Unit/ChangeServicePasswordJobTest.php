<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ChangeServicePasswordJob;
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
 * Changing a cPanel password runs in the background (ChangeServicePasswordJob)
 * so the client's browser isn't blocked on the WHM passwd call. These tests
 * drive handle() against the real ProvisioningService with a scripted fake
 * cPanel module and verify the local password is only rewritten after the
 * module confirms success, and that the client is emailed the outcome.
 */
final class ChangeServicePasswordJobTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private int $clientId;
    private string $clientEmail = 'client@example.test';
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

        $this->clientId = (new ClientRepository($this->db))->create([
            'email' => $this->clientEmail,
            'password' => 'password123',
            'first_name' => 'Client',
            'last_name' => 'One',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function sentTo(string $subjectFragment): array
    {
        return array_values(array_filter($this->sentMails, static fn (array $m) => str_contains($m['subject'], $subjectFragment)));
    }

    private function insertService(string $storedPassword = 'old-pass-123'): int
    {
        $productGroupId = (new ProductGroupRepository($this->db))->create('Hosting', null);
        $serverGroupId = (new ServerGroupRepository($this->db))->create('Hosting');

        $serverId = (new ServerRepository($this->db))->create([
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

        $productId = (new ProductRepository($this->db))->create([
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
            'password' => $storedPassword,
            'status' => 'active',
            'next_due_date' => '2026-09-01',
        ]);
        $this->services->assignServer($serviceId, $serverId, 'cvuser1');

        return $serviceId;
    }

    public function test_job_is_plain_serializable_payload(): void
    {
        $job = new ChangeServicePasswordJob(7, 'NewPass123!');

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(ChangeServicePasswordJob::class, $restored);
        $this->assertSame(7, $restored->serviceId);
        $this->assertSame('NewPass123!', $restored->newPassword);
        $this->assertSame('default', $job->queue());
    }

    public function test_handle_updates_the_local_password_only_after_the_module_succeeds(): void
    {
        $serviceId = $this->insertService('old-pass-123');
        $this->module->changePasswordResult = ['success' => true, 'message' => 'passwd ok'];

        (new ChangeServicePasswordJob($serviceId, 'NewPass123!'))->handle();

        $this->assertCount(1, $this->module->changePasswordCalls);
        $this->assertSame('NewPass123!', $this->module->changePasswordCalls[0]['password'] ?? null);

        // Local record rewritten only now that WHM confirmed the change.
        $service = $this->services->find($serviceId);
        $this->assertSame('NewPass123!', (string) $service['password']);

        // Client emailed the success template (which confirms the change but,
        // for security, never echoes the new password).
        $mail = $this->sentTo('password has been updated');
        $this->assertCount(1, $mail);
        $this->assertSame($this->clientEmail, $mail[0]['to']);
        $this->assertStringContainsString('changed successfully', $mail[0]['html']);
        $this->assertStringNotContainsString('NewPass123!', $mail[0]['html']);
    }

    public function test_handle_does_not_rewrite_the_local_password_when_the_module_fails(): void
    {
        $serviceId = $this->insertService('old-pass-123');
        $this->module->changePasswordResult = ['success' => false, 'message' => 'passwd: account missing'];

        (new ChangeServicePasswordJob($serviceId, 'NewPass123!'))->handle();

        // The local record must NOT claim a password the server rejected.
        $service = $this->services->find($serviceId);
        $this->assertSame('old-pass-123', (string) $service['password']);

        // Activity logged as FAILED with the reason.
        $log = $this->db->selectOne("SELECT * FROM activity_log WHERE action = 'service.password_change_failed' AND subject_id = ?", [$serviceId]);
        $this->assertNotNull($log);
        $this->assertStringContainsString('account missing', $log['description']);

        // Client emailed the failure template with the exact reason.
        $mail = $this->sentTo('could not be changed');
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('account missing', $mail[0]['html']);
    }
}
