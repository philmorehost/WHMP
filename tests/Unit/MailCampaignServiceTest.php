<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientGroupRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Mail\LogMailer;
use CodeVault\Mail\Mailer;
use CodeVault\Marketing\MailCampaignRepository;
use CodeVault\Marketing\MailCampaignService;
use CodeVault\Queue\SyncQueue;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;

final class MailCampaignServiceTest extends DatabaseTestCase
{
    private MailCampaignRepository $campaigns;
    private ClientRepository $clients;
    private ClientGroupRepository $groups;
    private MailCampaignService $service;
    private EmailLogRepository $emailLog;
    private string $mailLogPath;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->mailLogPath = sys_get_temp_dir() . '/codevault-campaign-test-' . uniqid() . '.log';

        $this->campaigns = new MailCampaignRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->groups = new ClientGroupRepository($this->db);
        $this->emailLog = new EmailLogRepository($this->db);

        $dispatcher = new EmailDispatcher(new EmailTemplateRepository($this->db), $this->emailLog, new SyncQueue());
        $this->service = new MailCampaignService($this->campaigns, $this->clients, $dispatcher);

        $container = new Container();
        $container->instance(Mailer::class, new LogMailer($this->mailLogPath));
        $container->instance(EmailLogRepository::class, $this->emailLog);
        App::setContainer($container);
    }

    protected function tearDown(): void
    {
        @unlink($this->mailLogPath);
        parent::tearDown();
    }

    private function createActiveClient(string $email, ?int $groupId = null): int
    {
        return $this->clients->create([
            'email' => $email,
            'password' => 'secret123',
            'first_name' => 'Test',
            'last_name' => 'Client',
            'client_group_id' => $groupId,
        ]);
    }

    public function test_send_delivers_to_every_active_client_when_no_group_is_set(): void
    {
        $this->createActiveClient('a@example.test');
        $this->createActiveClient('b@example.test');

        $campaignId = $this->campaigns->create('Big Sale', '<p>50% off</p>', null);
        $sent = $this->service->send($campaignId);

        $this->assertSame(2, $sent);
        $this->assertCount(2, $this->emailLog->recent(10));
    }

    public function test_send_only_delivers_to_the_targeted_group(): void
    {
        $groupId = $this->groups->create('VIP');
        $this->createActiveClient('vip@example.test', $groupId);
        $this->createActiveClient('regular@example.test');

        $campaignId = $this->campaigns->create('VIP Offer', '<p>Just for you</p>', $groupId);
        $sent = $this->service->send($campaignId);

        $this->assertSame(1, $sent);
        $this->assertSame('vip@example.test', $this->emailLog->recent(1)[0]['to_email']);
    }

    public function test_send_marks_the_campaign_sent_and_records_recipients(): void
    {
        $this->createActiveClient('a@example.test');
        $campaignId = $this->campaigns->create('Announcement', '<p>Hi</p>', null);

        $this->service->send($campaignId);

        $campaign = $this->campaigns->find($campaignId);
        $this->assertSame('sent', $campaign['status']);

        $recipients = $this->campaigns->recipients($campaignId);
        $this->assertCount(1, $recipients);
        $this->assertNotEmpty($recipients[0]['open_token']);
        $this->assertNull($recipients[0]['opened_at']);
    }

    public function test_send_generates_a_unique_open_token_per_recipient(): void
    {
        // LogMailer strip_tags()'s the body before logging (it's a
        // human-readable dev log, not a wire capture), so the embedded
        // <img> tracking pixel can't be asserted from the log file — the
        // thing that actually matters, a distinct token per recipient
        // (so opens can be attributed to the right person), is checked here.
        $this->createActiveClient('a@example.test');
        $this->createActiveClient('b@example.test');
        $campaignId = $this->campaigns->create('Announcement', '<p>Hi</p>', null);

        $this->service->send($campaignId);

        $recipients = $this->campaigns->recipients($campaignId);
        $this->assertNotSame($recipients[0]['open_token'], $recipients[1]['open_token']);
        $this->assertSame(64, strlen($recipients[0]['open_token']));
    }

    public function test_send_is_a_no_op_for_an_already_sent_campaign(): void
    {
        $this->createActiveClient('a@example.test');
        $campaignId = $this->campaigns->create('Announcement', '<p>Hi</p>', null);

        $this->service->send($campaignId);
        $secondRun = $this->service->send($campaignId);

        $this->assertSame(0, $secondRun);
        $this->assertCount(1, $this->emailLog->recent(10));
    }

    public function test_record_open_marks_the_recipient_opened_exactly_once(): void
    {
        $this->createActiveClient('a@example.test');
        $campaignId = $this->campaigns->create('Announcement', '<p>Hi</p>', null);
        $this->service->send($campaignId);

        $token = $this->campaigns->recipients($campaignId)[0]['open_token'];

        $firstOpen = $this->campaigns->recordOpen($token);
        $secondOpen = $this->campaigns->recordOpen($token);

        $this->assertTrue($firstOpen);
        $this->assertFalse($secondOpen, 'a second open of the same pixel must not overwrite the first opened_at');
    }

    public function test_record_open_with_an_unknown_token_is_a_no_op(): void
    {
        $this->assertFalse($this->campaigns->recordOpen('does-not-exist'));
    }

    public function test_send_embeds_the_open_tracking_pixel_in_the_raw_html_sent_to_the_mailer(): void
    {
        // A spy Mailer, since LogMailer strip_tags()'s its output and can't
        // show whether the <img> tag actually made it into the HTML.
        $spy = new class implements Mailer {
            public array $sent = [];

            public function send(string $to, string $subject, string $html): void
            {
                $this->sent[] = $html;
            }
        };

        $container = new Container();
        $container->instance(Mailer::class, $spy);
        $container->instance(EmailLogRepository::class, $this->emailLog);
        App::setContainer($container);

        $this->createActiveClient('a@example.test');
        $campaignId = $this->campaigns->create('Announcement', '<p>Hi</p>', null);
        $this->service->send($campaignId);

        $token = $this->campaigns->recipients($campaignId)[0]['open_token'];
        $this->assertStringContainsString("<img src=\"/campaigns/track/{$token}\"", $spy->sent[0]);
    }

    public function test_send_to_inactive_targets_only_accounts_with_no_active_product_or_domain(): void
    {
        // Bare account — no service, no domain → targeted.
        $bare = $this->createActiveClient('bare@example.test');

        // Has an active service → excluded.
        $withService = $this->createActiveClient('service@example.test');

        // Has an active domain → excluded.
        $withDomain = $this->createActiveClient('domain@example.test');

        // Has only a suspended service → targeted (nothing is active).
        $suspended = $this->createActiveClient('suspended@example.test');

        $now = date('Y-m-d H:i:s');
        $this->db->connection()->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->db->insert(
                'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$withService, 1, 'Hosting', 'monthly', 10.00, 'active', '2030-01-01', $now, $now]
            );
            $this->db->insert(
                'INSERT INTO domains (client_id, domain_name, tld, registrar_slug, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$withDomain, 'has-domain.test', 'test', 'local', 'active', $now, $now]
            );
            $this->db->insert(
                'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$suspended, 1, 'Hosting', 'monthly', 10.00, 'suspended', '2030-01-01', $now, $now]
            );
        } finally {
            $this->db->connection()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $campaignId = $this->campaigns->create('Re-engage', '<p>Come back</p>', null, null, null, true);
        $sent = $this->service->send($campaignId);

        $this->assertSame(2, $sent);

        $emails = array_map(static fn (array $r): string => (string) $r['to_email'], $this->emailLog->recent(10));
        sort($emails);
        $this->assertSame(['bare@example.test', 'suspended@example.test'], $emails);
    }

    public function test_queue_to_inactive_resolves_the_same_audience(): void
    {
        $bare = $this->createActiveClient('bare@example.test');
        $withService = $this->createActiveClient('service@example.test');

        $now = date('Y-m-d H:i:s');
        $this->db->connection()->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->db->insert(
                'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$withService, 1, 'Hosting', 'monthly', 10.00, 'active', '2030-01-01', $now, $now]
            );
        } finally {
            $this->db->connection()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $campaignId = $this->campaigns->create('Re-engage', '<p>Come back</p>', null, null, null, true);
        $queued = $this->service->queue($campaignId);

        $this->assertSame(1, $queued);

        $recipients = $this->campaigns->recipients($campaignId);
        $this->assertCount(1, $recipients);
        $this->assertSame('bare@example.test', $recipients[0]['email']);
    }
}
