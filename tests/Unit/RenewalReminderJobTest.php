<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\RenewalReminderJob;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Mail\LogMailer;
use CodeVault\Mail\Mailer;
use CodeVault\Queue\SyncQueue;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class RenewalReminderJobTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private ClientRepository $clients;
    private RenewalReminderJob $job;
    private EmailLogRepository $emailLog;
    private int $clientId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->services = new ServiceRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->emailLog = new EmailLogRepository($this->db);

        $dispatcher = new EmailDispatcher(new EmailTemplateRepository($this->db), $this->emailLog, new SyncQueue());
        $this->job = new RenewalReminderJob($this->services, $this->clients, $dispatcher);

        $mailLogPath = sys_get_temp_dir() . '/codevault-renewal-test-' . uniqid() . '.log';
        $container = new Container();
        $container->instance(Mailer::class, new LogMailer($mailLogPath));
        $container->instance(EmailLogRepository::class, $this->emailLog);
        App::setContainer($container);

        $this->clientId = $this->clients->create([
            'email' => 'renewal-reminder@example.test',
            'password' => 'secret123',
            'first_name' => 'Reminder',
            'last_name' => 'Client',
        ]);

        $groups = new ProductGroupRepository($this->db);
        $products = new ProductRepository($this->db);
        $groupId = $groups->create('Hosting', null);
        $this->productId = $products->create(['product_group_id' => $groupId, 'name' => 'Starter']);
    }

    private function createService(string $nextDueDate, ?string $remindedAt = null): int
    {
        $id = $this->services->create([
            'client_id' => $this->clientId,
            'product_id' => $this->productId,
            'product_name' => 'Starter',
            'billing_cycle' => 'monthly',
            'amount' => 9.99,
            'status' => 'active',
            'next_due_date' => $nextDueDate,
        ]);

        if ($remindedAt !== null) {
            $this->db->update('UPDATE services SET renewal_reminded_at = ? WHERE id = ?', [$remindedAt, $id]);
        }

        return $id;
    }

    public function test_reminds_for_a_service_due_within_the_window(): void
    {
        $dueDate = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
        $this->createService($dueDate);

        $this->job->handle();

        $entries = $this->emailLog->recent(10);
        $this->assertCount(1, $entries);
        $this->assertSame('renewal-reminder@example.test', $entries[0]['to_email']);
    }

    public function test_does_not_remind_for_a_service_outside_the_window(): void
    {
        $dueDate = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
        $this->createService($dueDate);

        $this->job->handle();

        $this->assertCount(0, $this->emailLog->recent(10));
    }

    public function test_does_not_remind_twice_for_the_same_cycle(): void
    {
        $dueDate = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
        $this->createService($dueDate, remindedAt: (new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $this->job->handle();

        $this->assertCount(0, $this->emailLog->recent(10));
    }

    public function test_stamps_renewal_reminded_at_after_sending(): void
    {
        $dueDate = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
        $serviceId = $this->createService($dueDate);

        $this->job->handle();

        $service = $this->services->find($serviceId);
        $this->assertNotNull($service['renewal_reminded_at']);
    }

    public function test_reminds_again_after_advance_next_due_date_resets_the_flag(): void
    {
        // Regression for the exact bug this job is designed to avoid: once
        // reminded_at is stamped, the service must NOT stay silently
        // un-remindable forever — advancing the cycle must reset it.
        $dueDate = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
        $serviceId = $this->createService($dueDate, remindedAt: (new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $this->services->advanceNextDueDate($serviceId, (new DateTimeImmutable('+35 days'))->format('Y-m-d'));
        $service = $this->services->find($serviceId);
        $this->assertNull($service['renewal_reminded_at']);

        // Move the newly-advanced due date back into the reminder window and confirm it fires again.
        $this->db->update('UPDATE services SET next_due_date = ? WHERE id = ?', [(new DateTimeImmutable('+5 days'))->format('Y-m-d'), $serviceId]);
        $this->job->handle();

        $this->assertCount(1, $this->emailLog->recent(10));
    }

    public function test_skips_a_service_whose_client_no_longer_exists(): void
    {
        $dueDate = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
        $serviceId = $this->createService($dueDate);
        $this->db->delete('DELETE FROM clients WHERE id = ?', [$this->clientId]);

        // The service row itself is gone too (FK CASCADE) — handle() must
        // simply find nothing due, not error.
        $this->job->handle();

        $this->assertCount(0, $this->emailLog->recent(10));
        $this->assertNull($this->services->find($serviceId));
    }
}
