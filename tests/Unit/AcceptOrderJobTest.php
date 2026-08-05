<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\AcceptOrderJob;
use CodeVault\Billing\OrderRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Mail\Mailer;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * Order acceptance now happens in the background (AcceptOrderJob) so the
 * admin's Accept click returns immediately. These tests drive the job's
 * handle() directly — the exact code a queue worker runs — and verify it
 * still performs the old accept() work (provisioning, activity log, hooks)
 * plus the new admin completion/failure emails.
 */
final class AcceptOrderJobTest extends DatabaseTestCase
{
    private ProductRepository $products;
    private ServiceRepository $services;
    private OrderRepository $orders;
    private ClientRepository $clients;
    private int $clientId;
    private int $adminId;
    private string $adminEmail = 'ops@example.test';
    /** @var array<int, array{to: string, subject: string, html: string}> */
    private array $sentMails = [];

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        $container = \CodeVault\Support\App::container();
        $container->instance(Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->products = new ProductRepository($this->db);
        $this->services = new ServiceRepository($this->db);
        $this->orders = new OrderRepository($this->db);
        $this->clients = new ClientRepository($this->db);

        $container->instance(ProductRepository::class, $this->products);
        $container->instance(ServiceRepository::class, $this->services);
        $container->instance(OrderRepository::class, $this->orders);
        $container->instance(ClientRepository::class, $this->clients);

        $serverGroups = new ServerGroupRepository($this->db);
        $servers = new ServerRepository($this->db);
        $container->instance(ServerRepository::class, $servers);
        $container->instance(ServerGroupRepository::class, $serverGroups);

        $hooks = $container->make(HookDispatcher::class);
        $modules = $container->make(ModuleManager::class);
        $provisioning = new ProvisioningService($this->services, $this->products, $servers, $modules, $hooks);
        $container->instance(ProvisioningService::class, $provisioning);

        // Record outbound mail in-memory instead of attempting SMTP.
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

        $this->clientId = $this->clients->create([
            'email' => 'acceptee@example.test',
            'password' => 'password123',
            'first_name' => 'Accept',
            'last_name' => 'Me',
        ]);

        $this->adminId = (new AdminRepository($this->db))->create('ops', $this->adminEmail, 'secret123', 'Ops Admin', null);
    }

    /** @return array<int, array<string, mixed>> */
    private function sentTo(string $templateSubjectFragment): array
    {
        return array_values(array_filter($this->sentMails, static fn (array $m) => str_contains($m['subject'], $templateSubjectFragment)));
    }

    private function insertOrder(string $status = 'pending'): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, discount_amount, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $status, 19.99, 0.0, null, 1.0, $now, $now]
        );
    }

    private function insertService(int $orderId, int $productId, string $status = 'pending'): int
    {
        return $this->services->create([
            'client_id' => $this->clientId,
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_name' => 'Test Product',
            'billing_cycle' => 'monthly',
            'amount' => 9.99,
            'status' => $status,
            'next_due_date' => (new DateTimeImmutable('+1 month'))->format('Y-m-d'),
        ]);
    }

    public function test_job_is_plain_serializable_payload(): void
    {
        $job = new AcceptOrderJob(7, 3, '203.0.113.10');

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(AcceptOrderJob::class, $restored);
        $this->assertSame(7, $restored->orderId);
        $this->assertSame(3, $restored->adminId);
        $this->assertSame('203.0.113.10', $restored->adminIp);
        $this->assertSame('default', $job->queue());
    }

    public function test_handle_accepts_an_order_and_emails_admins_with_a_success_summary(): void
    {
        $groups = new ProductGroupRepository($this->db);
        $productId = $this->products->create([
            'product_group_id' => $groups->create('Hosting', null),
            'name' => 'Manual Server',
            'autosetup' => 'off',
        ]);
        $orderId = $this->insertOrder();
        $this->insertService($orderId, $productId);

        (new AcceptOrderJob($orderId, $this->adminId, '203.0.113.10'))->handle();

        // The old accept() bookkeeping still happens.
        $accepted = $this->db->selectOne("SELECT * FROM activity_log WHERE action = 'order.accepted' AND subject_id = ?", [$orderId]);
        $this->assertNotNull($accepted, 'handle() must log order.accepted');
        $this->assertSame($this->adminId, (int) $accepted['actor_id']);

        $manual = $this->db->selectOne("SELECT * FROM activity_log WHERE action = 'service.manual_setup_required'");
        $this->assertNotNull($manual, 'manual-setup services are skipped, not provisioned');

        // The admin gets the completion email.
        $emails = $this->db->select("SELECT * FROM email_log WHERE template_key = 'order_acceptance_completed'");
        $this->assertCount(1, $emails);
        $this->assertSame($this->adminEmail, $emails[0]['to_email']);

        $summaryMail = $this->sentTo('Acceptance Completed');
        $this->assertCount(1, $summaryMail);
        $this->assertStringContainsString('All services and domains were provisioned successfully.', $summaryMail[0]['html']);
    }

    public function test_handle_reports_exact_failure_reasons_in_the_admin_summary_email(): void
    {
        // A product aimed at a server group with no servers → provisioning
        // must fail with a concrete reason rather than silently skipping.
        $groups = new ProductGroupRepository($this->db);
        $emptyGroupId = (new ServerGroupRepository($this->db))->create('Empty Group');
        $productId = $this->products->create([
            'product_group_id' => $groups->create('Hosting', null),
            'name' => 'No Server Product',
            'server_group_id' => $emptyGroupId,
            'autosetup' => 'on_accept',
        ]);
        $orderId = $this->insertOrder();
        $this->insertService($orderId, $productId);

        (new AcceptOrderJob($orderId, $this->adminId, '203.0.113.10'))->handle();

        $failed = $this->db->selectOne("SELECT * FROM activity_log WHERE action = 'service.provisioning_failed'");
        $this->assertNotNull($failed);
        $this->assertStringContainsString('No active server', $failed['description']);

        $summaryMail = $this->sentTo('Acceptance Completed');
        $this->assertCount(1, $summaryMail);
        $this->assertStringContainsString('could not be provisioned', $summaryMail[0]['html']);
        $this->assertStringContainsString('No active server available in the assigned server group', $summaryMail[0]['html']);
    }
}
