<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\ExpiringReminderJob;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainRepository;
use CodeVault\Mail\Mailer;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * The admin "email clients expiring in 7 days" action runs in the background
 * (ExpiringReminderJob). These tests drive handle() and verify each affected
 * client gets a personalized email (their service/domain names, due dates,
 * amounts + the admin's promotional message) and the admin gets a summary.
 */
final class ExpiringReminderJobTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private DomainRepository $domains;
    private int $clientId;
    private int $adminId;
    private string $clientEmail = 'client@example.test';
    private string $adminEmail = 'ops@example.test';
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
        $this->domains = new DomainRepository($this->db);
        $container->instance(ServiceRepository::class, $this->services);
        $container->instance(DomainRepository::class, $this->domains);
        $container->instance(ProductRepository::class, new ProductRepository($this->db));

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

        $this->adminId = (new AdminRepository($this->db))->create('ops', $this->adminEmail, 'secret123', 'Ops Admin', null);
    }

    /** @return array<int, array<string, mixed>> */
    private function sentTo(string $subjectFragment): array
    {
        return array_values(array_filter($this->sentMails, static fn (array $m) => str_contains($m['subject'], $subjectFragment)));
    }

    private function insertService(string $dueDate): int
    {
        $groupId = (new ProductGroupRepository($this->db))->create('Hosting', null);
        $productId = (new ProductRepository($this->db))->create([
            'product_group_id' => $groupId,
            'name' => 'PMH2 Shared',
            'stock_quantity' => 5,
            'type' => 'shared',
        ]);

        return $this->services->create([
            'client_id' => $this->clientId,
            'product_id' => $productId,
            'product_name' => 'PMH2 Shared',
            'billing_cycle' => 'monthly',
            'amount' => 15.00,
            'status' => 'active',
            'next_due_date' => $dueDate,
        ]);
    }

    private function insertDomain(string $dueDate): int
    {
        return $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => 'example.com',
            'registrar_slug' => 'local',
            'status' => 'active',
            'next_due_date' => $dueDate,
            'auto_renew' => 1,
            'amount' => 12.00,
        ]);
    }

    public function test_job_is_plain_serializable_payload(): void
    {
        $job = new ExpiringReminderJob('Renew soon!', 3, '203.0.113.10');

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(ExpiringReminderJob::class, $restored);
        $this->assertSame('Renew soon!', $restored->message);
        $this->assertSame(3, $restored->adminId);
        $this->assertSame('203.0.113.10', $restored->adminIp);
        $this->assertSame('default', $job->queue());
    }

    public function test_handle_sends_personalized_emails_to_each_client_and_reports_to_admins(): void
    {
        $due = (new DateTimeImmutable('+3 days'))->format('Y-m-d');
        $this->insertService($due);
        $this->insertDomain($due);

        (new ExpiringReminderJob('Renew on time and keep your site online!', $this->adminId, '203.0.113.10'))->handle();

        // The client got one personalized reminder carrying their items + promo.
        $clientMail = $this->sentTo('renewals are coming up');
        $this->assertCount(1, $clientMail);
        $this->assertSame($this->clientEmail, $clientMail[0]['to']);
        $this->assertStringContainsString('PMH2 Shared', $clientMail[0]['html']);
        $this->assertStringContainsString('example.com', $clientMail[0]['html']);
        $this->assertStringContainsString($due, $clientMail[0]['html']);
        $this->assertStringContainsString('$15.00', $clientMail[0]['html']);
        $this->assertStringContainsString('$12.00', $clientMail[0]['html']);
        $this->assertStringContainsString('Renew on time and keep your site online!', $clientMail[0]['html']);

        // The admin got the summary report.
        $adminMail = $this->sentTo('Reminder Emails Sent');
        $this->assertCount(1, $adminMail);
        $this->assertSame($this->adminEmail, $adminMail[0]['to']);
        $this->assertStringContainsString('<strong>1</strong> email(s) sent', $adminMail[0]['html']);
    }

    public function test_handle_skips_clients_without_an_email_address(): void
    {
        $due = (new DateTimeImmutable('+3 days'))->format('Y-m-d');
        $noEmailClientId = (new ClientRepository($this->db))->create([
            'email' => '',
            'password' => 'password123',
            'first_name' => 'No',
            'last_name' => 'Email',
        ]);

        $groupId = (new ProductGroupRepository($this->db))->create('Hosting', null);
        $productId = (new ProductRepository($this->db))->create([
            'product_group_id' => $groupId,
            'name' => 'PMH2 Shared',
            'stock_quantity' => 5,
            'type' => 'shared',
        ]);
        $this->services->create([
            'client_id' => $noEmailClientId,
            'product_id' => $productId,
            'product_name' => 'PMH2 Shared',
            'billing_cycle' => 'monthly',
            'amount' => 15.00,
            'status' => 'active',
            'next_due_date' => $due,
        ]);

        (new ExpiringReminderJob('Renew soon!', $this->adminId, '203.0.113.10'))->handle();

        // No client email sent; the admin report reflects the skip.
        $this->assertCount(0, $this->sentTo('renewals are coming up'));

        $adminMail = $this->sentTo('Reminder Emails Sent');
        $this->assertCount(1, $adminMail);
        $this->assertStringContainsString('1 skipped', $adminMail[0]['html']);
    }
}
