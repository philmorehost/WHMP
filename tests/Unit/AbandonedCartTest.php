<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Cart\AbandonedCartJob;
use CodeVault\Cart\AbandonedCartRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Mail\LogMailer;
use CodeVault\Mail\Mailer;
use CodeVault\Queue\SyncQueue;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;

final class AbandonedCartTest extends DatabaseTestCase
{
    private AbandonedCartRepository $carts;
    private EmailLogRepository $emailLog;
    private AbandonedCartJob $job;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->carts = new AbandonedCartRepository($this->db);
        $this->emailLog = new EmailLogRepository($this->db);

        $dispatcher = new EmailDispatcher(new EmailTemplateRepository($this->db), $this->emailLog, new SyncQueue());

        $clients = new ClientRepository($this->db);
        $this->clientId = $clients->create([
            'email' => 'abandoned@example.test',
            'password' => 'secret123',
            'first_name' => 'Abandoned',
            'last_name' => 'Client',
        ]);

        $this->job = new AbandonedCartJob(
            $this->carts,
            $dispatcher,
            $clients,
            new CurrencyRepository($this->db),
            new Config(dirname(__DIR__, 2)),
            new SettingsRepository($this->db)
        );

        $mailLogPath = sys_get_temp_dir() . '/codevault-abandoned-' . uniqid() . '.log';
        $container = new Container();
        $container->instance(Mailer::class, new LogMailer($mailLogPath));
        $container->instance(EmailLogRepository::class, $this->emailLog);
        App::setContainer($container);
    }

    private function upsert(string $sessionId, ?int $clientId = null, ?string $email = null, ?string $updatedAt = null): void
    {
        $this->carts->upsertBySession(
            $sessionId,
            [['product_id' => 1, 'billing_cycle' => 'monthly', 'quantity' => 1, 'options' => []]],
            [['product_name' => 'Starter', 'line_total' => 9.99]],
            null,
            9.99,
            $clientId,
            $email,
            1
        );

        if ($updatedAt !== null) {
            $this->db->update('UPDATE abandoned_carts SET updated_at = ? WHERE session_id = ?', [$updatedAt, $sessionId]);
        }
    }

    public function test_upsert_creates_and_refreshes_a_session_snapshot(): void
    {
        $this->upsert('sess-1', $this->clientId, 'abandoned@example.test');
        $this->upsert('sess-1', $this->clientId, 'abandoned@example.test');

        $rows = $this->db->select('SELECT * FROM abandoned_carts WHERE session_id = ?', ['sess-1']);
        $this->assertCount(1, $rows);
        $this->assertSame($this->clientId, (int) $rows[0]['client_id']);
    }

    public function test_stale_returns_only_carts_older_than_the_idle_threshold(): void
    {
        $this->upsert('old-sess', $this->clientId, 'abandoned@example.test', '2020-01-01 00:00:00');
        $this->upsert('fresh-sess', $this->clientId, 'abandoned@example.test', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

        $stale = $this->carts->stale(120);
        $ids = array_map(static fn (array $row) => $row['session_id'], $stale);

        $this->assertContains('old-sess', $ids);
        $this->assertNotContains('fresh-sess', $ids);
    }

    public function test_stale_excludes_recovered_carts(): void
    {
        $this->upsert('sess-1', $this->clientId, 'abandoned@example.test', '2020-01-01 00:00:00');
        $this->carts->markRecoveredBySession('sess-1');

        $this->assertSame([], $this->carts->stale(120));
    }

    public function test_job_emails_and_stamps_reminder_for_stale_cart(): void
    {
        $this->upsert('sess-1', $this->clientId, 'abandoned@example.test', '2020-01-01 00:00:00');

        $this->job->handle();

        $entries = $this->emailLog->recent(10);
        $this->assertCount(1, $entries);
        $this->assertSame('abandoned@example.test', $entries[0]['to_email']);

        $row = $this->db->selectOne('SELECT * FROM abandoned_carts WHERE session_id = ?', ['sess-1']);
        $this->assertNotNull($row['reminder_sent_at']);
    }

    public function test_job_skips_carts_with_no_email(): void
    {
        // Guest cart with no captured email — must not be emailed, and must
        // stay eligible for a later run in case the visitor logs in and the
        // snapshot picks up a client email.
        $this->upsert('sess-2', null, null, '2020-01-01 00:00:00');

        $this->job->handle();

        $this->assertSame([], $this->emailLog->recent(10));

        $row = $this->db->selectOne('SELECT * FROM abandoned_carts WHERE session_id = ?', ['sess-2']);
        $this->assertNull($row['reminder_sent_at']);
    }

    public function test_job_resolves_client_email_when_snapshot_email_is_null(): void
    {
        // Logged-in client, but snapshot has no email column — the job must
        // fall back to the clients table.
        $this->upsert('sess-3', $this->clientId, null, '2020-01-01 00:00:00');

        $this->job->handle();

        $entries = $this->emailLog->recent(10);
        $this->assertCount(1, $entries);
        $this->assertSame('abandoned@example.test', $entries[0]['to_email']);
    }

    public function test_job_does_not_resend_once_reminded(): void
    {
        $this->upsert('sess-4', $this->clientId, 'abandoned@example.test', '2020-01-01 00:00:00');

        $this->job->handle();
        $this->job->handle();

        $this->assertCount(1, $this->emailLog->recent(10));
    }
}
