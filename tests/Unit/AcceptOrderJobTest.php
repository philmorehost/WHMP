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
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Mail\Mailer;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Modules\RegistrarModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Tests\Fixtures\FakeRegistrarModule;
use CodeVault\Tests\Fixtures\ThrowingProvisioningModule;
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

    /**
     * Reproduction for "admin approves an order with service + domain, only
     * the hosting account is provisioned — the domain is never registered
     * until the admin registers it by hand". This is the exact scenario:
     * one pending cPanel service + one pending domain on the same order,
     * both driven through AcceptOrderJob::handle(). If this test goes red,
     * the job's domain loop is where the bug lives.
     */
    public function test_handle_registers_a_pending_domain_alongside_a_service(): void
    {
        $container = \CodeVault\Support\App::container();

        // A shared-hosting product aimed at a group with a real server, so the
        // service leg provisions and activates. The "local" module just writes
        // a file, which keeps the test off the network; the domain leg is what
        // this test actually exercises.
        $serverGroups = new ServerGroupRepository($this->db);
        $serverGroupId = $serverGroups->create('Hosting Group');
        (new ServerRepository($this->db))->create([
            'server_group_id' => $serverGroupId,
            'name' => 'Hosting Server',
            'hostname' => 'srv.test.local',
            'module_slug' => 'local',
        ]);
        $localStorageDir = sys_get_temp_dir() . '/codevault-acceptjob-prov-' . uniqid();
        @mkdir($localStorageDir);
        $modules = $container->make(ModuleManager::class);
        $modules->register(ProvisioningModule::class, 'local', new \CodeVault\Provisioning\LocalProvisioningModule($localStorageDir));

        $groups = new ProductGroupRepository($this->db);
        $productId = $this->products->create([
            'product_group_id' => $groups->create('Hosting', null),
            'server_group_id' => $serverGroupId,
            'name' => 'Shared Hosting',
            'autosetup' => 'on_accept',
        ]);

        // A registrable TLD (autosetup != off, so acceptance must register it).
        $domainPricing = new DomainPricingRepository($this->db);
        $domainPricing->save([
            'tld' => '.test',
            'registrar_slug' => 'fake',
            'register_price' => 10.0,
            'transfer_price' => 10.0,
            'renew_price' => 10.0,
            'autosetup_registration' => 'payment',
        ]);

        // Wire a scriptable registrar module in under the same slug the
        // domain row carries, so DomainService resolves it.
        $fakeRegistrar = new FakeRegistrarModule();
        $modules->register(RegistrarModule::class, 'fake', $fakeRegistrar);

        // The order, exactly as checkout leaves it for an on_accept product:
        // a pending service and a pending domain sharing order_id.
        $orderId = $this->insertOrder();
        $this->insertService($orderId, $productId);
        (new DomainRepository($this->db))->create([
            'client_id' => $this->clientId,
            'order_id' => $orderId,
            'domain_name' => 'example.test',
            'tld' => 'test',
            'registrar_slug' => 'fake',
            'status' => 'pending',
            'next_due_date' => (new DateTimeImmutable('+1 year'))->format('Y-m-d'),
            'auto_renew' => 1,
            'amount' => 10.0,
        ]);

        (new AcceptOrderJob($orderId, $this->adminId, '203.0.113.10'))->handle();

        $service = $this->db->selectOne('SELECT * FROM services WHERE order_id = ?', [$orderId]);
        $this->assertSame('active', $service['status'], 'AcceptOrderJob must still provision the service');

        $domain = $this->db->selectOne('SELECT * FROM domains WHERE order_id = ?', [$orderId]);
        $this->assertNotNull($domain, 'the pending domain must be attached to the order');
        $this->assertSame('active', $domain['status'], 'AcceptOrderJob must register a pending domain on the same order');
        $this->assertNotEmpty($domain['registration_date'], 'a successful registration stamps the registration date');
        $this->assertNotEmpty($fakeRegistrar->lastCall('register'), 'DomainService must call the registrar module');

        // The summary email must claim full success (no domain failure).
        $summaryMail = $this->sentTo('Acceptance Completed');
        $this->assertCount(1, $summaryMail);
        $this->assertStringContainsString('All services and domains were provisioned successfully.', $summaryMail[0]['html']);
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

    /**
     * Regression for "admin approves an order; only the hosting account is
     * processed, the domain is never registered". Before the per-item
     * try/catch hardening, a service module THROWING an exception (instead
     * of returning a result) aborted handle() entirely — the domain loop
     * never ran and the domain stayed pending forever. Every invoice item
     * must be attempted even when one of them explodes.
     */
    public function test_handle_still_registers_the_domain_when_a_service_module_throws(): void
    {
        $container = \CodeVault\Support\App::container();

        // A hosting product on a server group whose module throws on create().
        $serverGroups = new ServerGroupRepository($this->db);
        $serverGroupId = $serverGroups->create('Exploding Group');
        (new ServerRepository($this->db))->create([
            'server_group_id' => $serverGroupId,
            'name' => 'Exploding Server',
            'hostname' => 'boom.test.local',
            'module_slug' => 'throwing',
        ]);
        $throwing = new ThrowingProvisioningModule();
        $modules = $container->make(ModuleManager::class);
        $modules->register(ProvisioningModule::class, 'throwing', $throwing);

        $groups = new ProductGroupRepository($this->db);
        $productId = $this->products->create([
            'product_group_id' => $groups->create('Hosting', null),
            'server_group_id' => $serverGroupId,
            'name' => 'Boom Hosting',
            'autosetup' => 'on_accept',
        ]);

        (new DomainPricingRepository($this->db))->save([
            'tld' => '.test',
            'registrar_slug' => 'fake',
            'register_price' => 10.0,
            'transfer_price' => 10.0,
            'renew_price' => 10.0,
            'autosetup_registration' => 'payment',
        ]);
        $fakeRegistrar = new FakeRegistrarModule();
        $modules->register(RegistrarModule::class, 'fake', $fakeRegistrar);

        $orderId = $this->insertOrder();
        $this->insertService($orderId, $productId);
        (new DomainRepository($this->db))->create([
            'client_id' => $this->clientId,
            'order_id' => $orderId,
            'domain_name' => 'boom.test',
            'tld' => 'test',
            'registrar_slug' => 'fake',
            'status' => 'pending',
            'next_due_date' => (new DateTimeImmutable('+1 year'))->format('Y-m-d'),
            'auto_renew' => 1,
            'amount' => 10.0,
        ]);

        (new AcceptOrderJob($orderId, $this->adminId, '203.0.113.10'))->handle();

        // The throwing service is reported as a failure — not silently swallowed.
        $failed = $this->db->selectOne("SELECT * FROM activity_log WHERE action = 'service.provisioning_failed'");
        $this->assertNotNull($failed);
        $this->assertStringContainsString('threw an exception', $failed['description']);

        // …but the domain on the SAME order is still registered.
        $domain = $this->db->selectOne('SELECT * FROM domains WHERE order_id = ?', [$orderId]);
        $this->assertSame('active', $domain['status'], 'a throwing service must not skip the domain loop');
        $this->assertNotEmpty($fakeRegistrar->lastCall('register'));

        $summaryMail = $this->sentTo('Acceptance Completed');
        $this->assertCount(1, $summaryMail);
        $this->assertStringContainsString('could not be provisioned', $summaryMail[0]['html']);
        $this->assertStringContainsString('WHM API call exploded', $summaryMail[0]['html']);
    }
}
