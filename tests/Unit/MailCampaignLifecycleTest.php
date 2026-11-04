<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientGroupRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Mail\Mailer;
use CodeVault\Marketing\MailCampaignRepository;
use CodeVault\Marketing\MailCampaignService;
use CodeVault\Queue\SyncQueue;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The lifecycle around an old campaign: editing a sent or drafted campaign's
 * content/audience, resending a sent campaign (to everyone, or only to people
 * who have not received it yet), and deleting old campaigns.
 *
 * Resends are "rounds": a resend appends new recipient rows in a new round
 * and never touches the previous round's rows — who received an earlier send,
 * and whether they opened it, is history.
 */
final class MailCampaignLifecycleTest extends DatabaseTestCase
{
    private MailCampaignRepository $campaigns;
    private ClientRepository $clients;
    private ClientGroupRepository $groups;
    private MailCampaignService $service;
    private EmailLogRepository $emailLog;
    private string $mailLogPath;
    private bool $hadAppUrl = false;
    private mixed $savedAppUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->mailLogPath = sys_get_temp_dir() . '/codevault-campaign-lifecycle-' . uniqid() . '.log';

        $this->campaigns = new MailCampaignRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->groups = new ClientGroupRepository($this->db);
        $this->emailLog = new EmailLogRepository($this->db);

        $this->hadAppUrl = array_key_exists('APP_URL', $_ENV);
        $this->savedAppUrl = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://billing.example.test';
        if (function_exists('brand_name_forget')) {
            \brand_name_forget();
        }

        $dispatcher = new EmailDispatcher(new EmailTemplateRepository($this->db), $this->emailLog, new SyncQueue());
        $this->service = new MailCampaignService($this->campaigns, $this->clients, $dispatcher);

        $container = new Container();
        $container->instance(Mailer::class, new \CodeVault\Mail\LogMailer($this->mailLogPath));
        $container->instance(EmailLogRepository::class, $this->emailLog);
        App::setContainer($container);
    }

    protected function tearDown(): void
    {
        @unlink($this->mailLogPath);
        if ($this->hadAppUrl) {
            $_ENV['APP_URL'] = $this->savedAppUrl;
        } else {
            unset($_ENV['APP_URL']);
        }
        if (function_exists('brand_name_forget')) {
            \brand_name_forget();
        }
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

    /**
     * Swaps in a spy mailer and returns it — the SyncQueue drains inline, so
     * everything service->send()/dispatchQueued() hand to the queue lands here.
     *
     * @return object{sent: array<int, array{to: string, subject: string, html: string}>, send(): void}&Mailer
     */
    private function captureCampaignMailer(): object
    {
        $spy = new class implements Mailer {
            /** @var array<int, array{to: string, subject: string, html: string}> */
            public array $sent = [];

            public function send(string $to, string $subject, string $html, ?array $from = null): void
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
            }
        };

        $container = new Container();
        $container->instance(Mailer::class, $spy);
        $container->instance(EmailLogRepository::class, $this->emailLog);
        App::setContainer($container);

        $dispatcher = new EmailDispatcher(new EmailTemplateRepository($this->db), $this->emailLog, new SyncQueue());
        $this->service = new MailCampaignService($this->campaigns, $this->clients, $dispatcher);

        return $spy;
    }

    /** Creates, queues and fully drains a campaign so it ends in the 'sent' state. */
    private function sendCampaign(string $subject, string $body): int
    {
        $this->captureCampaignMailer();

        $campaignId = $this->campaigns->create($subject, $body, null);
        $this->service->queue($campaignId);
        $this->service->dispatchQueued(500);

        $campaign = $this->campaigns->find($campaignId);
        $this->assertSame('sent', $campaign['status']);

        return $campaignId;
    }

    public function test_sent_campaign_content_can_be_edited(): void
    {
        $this->createActiveClient('a@example.test');
        $campaignId = $this->sendCampaign('Old subject', '<p>Old body</p>');

        $this->assertTrue($this->service->update($campaignId, 'New subject', '<p>New body</p>'));

        $campaign = $this->campaigns->find($campaignId);
        $this->assertSame('New subject', $campaign['subject']);
        $this->assertSame('<p>New body</p>', $campaign['body']);
    }

    public function test_draft_campaign_content_can_be_edited(): void
    {
        $campaignId = $this->campaigns->create('Draft', '<p>Draft body</p>', null);

        $this->assertTrue($this->service->update($campaignId, 'Edited draft', '<p>Edited body</p>'));

        $campaign = $this->campaigns->find($campaignId);
        $this->assertSame('Edited draft', $campaign['subject']);
    }

    public function test_sending_campaign_cannot_be_edited_until_paused(): void
    {
        $this->createActiveClient('a@example.test');
        $campaignId = $this->campaigns->create('Sending', '<p>Body</p>', null);
        $this->service->queue($campaignId);
        $this->assertSame('sending', $this->campaigns->find($campaignId)['status']);

        $this->assertFalse($this->service->update($campaignId, 'Nope', '<p>Nope</p>'));
        $this->assertSame('Sending', $this->campaigns->find($campaignId)['subject']);

        // Pausing unlocks editing — the pause → review → edit → resume flow.
        $this->service->pause($campaignId);
        $this->assertTrue($this->service->update($campaignId, 'Edited while paused', '<p>Body</p>'));
    }

    public function test_audience_can_be_edited_on_draft_and_sent_campaigns(): void
    {
        $groupId = $this->groups->create('VIP');
        $campaignId = $this->campaigns->create('Everyone', '<p>Hi</p>', null);

        $this->assertTrue($this->service->update($campaignId, 'Everyone', '<p>Hi</p>', [
            'client_group_id' => $groupId,
        ]));

        $campaign = $this->campaigns->find($campaignId);
        $this->assertSame($groupId, (int) $campaign['client_group_id']);

        // Sending closes the door: a paused campaign's pending recipients were
        // resolved at queue time, so its audience must stay frozen. (Content
        // is unchanged here too, so both writes report zero affected rows.)
        $this->service->queue($campaignId);
        $this->service->pause($campaignId);
        $this->assertFalse($this->service->update($campaignId, 'Everyone', '<p>Hi</p>', [
            'client_group_id' => null,
        ]));
        $this->assertSame($groupId, (int) $this->campaigns->find($campaignId)['client_group_id'],
            'the audience is untouched while the campaign is paused');
    }

    public function test_resend_queues_everyone_in_a_new_round(): void
    {
        $this->createActiveClient('a@example.test');
        $this->createActiveClient('b@example.test');
        $campaignId = $this->sendCampaign('Newsletter', '<p>Issue #1</p>');
        $this->assertSame(1, (int) $this->campaigns->find($campaignId)['round']);

        // A sign-up since the first send.
        $this->createActiveClient('c@example.test');

        $mailer = $this->captureCampaignMailer();
        $queued = $this->service->resend($campaignId);

        $this->assertSame(3, $queued, 'resend without the skip flag queues the whole current audience');

        $campaign = $this->campaigns->find($campaignId);
        $this->assertSame('sending', $campaign['status'], 'a resent campaign is back in front of the cron');
        $this->assertSame(2, (int) $campaign['round']);

        // Round 1's rows are history, untouched.
        $rows = $this->campaigns->recipients($campaignId);
        $this->assertCount(5, $rows);
        $round1 = array_filter($rows, static fn (array $r): bool => (int) $r['round'] === 1);
        $this->assertCount(2, $round1);
        foreach ($round1 as $row) {
            $this->assertNotNull($row['sent_at'], 'the earlier round keeps its sent history');
        }

        // The cron drains only the new round's rows.
        $sent = $this->service->dispatchQueued(500);
        $this->assertSame(3, $sent);
        $this->assertSame('sent', $this->campaigns->find($campaignId)['status']);

        $tos = array_map(static fn (array $mail): string => $mail['to'], $mailer->sent);
        $this->assertEqualsCanonicalizing(['a@example.test', 'b@example.test', 'c@example.test'], $tos);
    }

    public function test_resend_with_skip_only_queues_people_who_never_received_it(): void
    {
        $this->createActiveClient('a@example.test');
        $this->createActiveClient('b@example.test');
        $campaignId = $this->sendCampaign('Welcome series', '<p>Hello</p>');

        $this->createActiveClient('c@example.test');

        $mailer = $this->captureCampaignMailer();
        $queued = $this->service->resend($campaignId, true);

        $this->assertSame(1, $queued, 'only the new sign-up is queued');
        $this->assertCount(1, $mailer->sent);
        $this->assertSame('c@example.test', $mailer->sent[0]['to']);

        $campaign = $this->campaigns->find($campaignId);
        $this->assertSame(2, (int) $campaign['round']);
        $this->assertCount(3, $this->campaigns->recipients($campaignId));
    }

    public function test_resend_with_no_new_recipients_changes_nothing(): void
    {
        $this->createActiveClient('a@example.test');
        $campaignId = $this->sendCampaign('One-off', '<p>Hi</p>');
        $before = $this->campaigns->find($campaignId);

        $this->assertSame(0, $this->service->resend($campaignId, true));

        $after = $this->campaigns->find($campaignId);
        $this->assertSame('sent', $after['status'], 'an empty resend must not reopen the campaign');
        $this->assertSame((int) $before['round'], (int) $after['round']);
        $this->assertSame($before['sent_at'], $after['sent_at']);
        $this->assertCount(1, $this->campaigns->recipients($campaignId));
    }

    public function test_resend_rejects_campaigns_that_are_not_sent(): void
    {
        $this->createActiveClient('a@example.test');

        $draftId = $this->campaigns->create('Draft', '<p>Body</p>', null);
        $this->assertSame(0, $this->service->resend($draftId));
        $this->assertSame('draft', $this->campaigns->find($draftId)['status'], 'use the normal send for drafts');

        $sendingId = $this->campaigns->create('Sending', '<p>Body</p>', null);
        $this->service->queue($sendingId);
        $this->assertSame(0, $this->service->resend($sendingId));
        $this->assertSame('sending', $this->campaigns->find($sendingId)['status']);
    }

    public function test_resend_picks_up_audience_edits(): void
    {
        $a = $this->createActiveClient('a@example.test');
        $campaignId = $this->sendCampaign('Group only', '<p>Hi</p>');

        // Narrow the audience to a fresh group after the first send.
        $groupId = $this->groups->create('Later buyers');
        $b = $this->createActiveClient('b@example.test', $groupId);
        $this->createActiveClient('c@example.test');

        $this->service->update($campaignId, 'Group only', '<p>Hi</p>', ['client_group_id' => $groupId]);

        $this->assertSame(1, $this->service->resend($campaignId, true), 'only b is in the edited audience and new');

        $mailer = $this->captureCampaignMailer();
        $this->service->dispatchQueued(500);
        $this->assertCount(1, $mailer->sent);
        $this->assertSame('b@example.test', $mailer->sent[0]['to']);
    }

    public function test_resend_skip_counts_recipient_rows_not_successful_sends(): void
    {
        // A recipient whose hand-off to the mail queue failed keeps a pending
        // row (sent_at NULL, send_error set). They already HAVE a recipient
        // row, so the skip flag does not add a second one for them — their
        // original row is what retries once the campaign is sending again.
        $this->createActiveClient('a@example.test');
        $campaignId = $this->sendCampaign('Flaky handoff', '<p>Hi</p>');

        $recipients = $this->campaigns->recipients($campaignId);
        $this->assertCount(1, $recipients);
        $this->db->update(
            "UPDATE mail_campaign_recipients SET sent_at = NULL, send_error = 'redis down' WHERE id = ?",
            [(int) $recipients[0]['id']]
        );

        $this->assertSame(0, $this->service->resend($campaignId, true), 'a pending row counts as already-covered');
        $this->assertSame('sent', $this->campaigns->find($campaignId)['status']);
        $this->assertCount(1, $this->campaigns->recipients($campaignId), 'no duplicate row was added');
    }

    public function test_resend_everyone_retries_a_failed_handoff_row_and_adds_new_round_rows(): void
    {
        $this->createActiveClient('a@example.test');
        $this->createActiveClient('b@example.test');
        $campaignId = $this->sendCampaign('Everyone retry', '<p>Hi</p>');

        $recipients = $this->campaigns->recipients($campaignId);
        $this->db->update(
            "UPDATE mail_campaign_recipients SET sent_at = NULL, send_error = 'redis down' WHERE id = ?",
            [(int) $recipients[0]['id']]
        );

        // "Send again to everyone": both get fresh rows in a new round, and
        // the drain retries a's still-pending round-1 row alongside them —
        // so a hears the campaign twice rather than never.
        $this->assertSame(2, $this->service->resend($campaignId));
        $this->assertSame(2, (int) $this->campaigns->find($campaignId)['round']);

        $sent = $this->service->dispatchQueued(500);
        $this->assertSame(3, $sent, 'two new-round rows plus the retried pending row');

        $rows = $this->campaigns->recipients($campaignId);
        $pending = array_filter($rows, static fn (array $r): bool => $r['sent_at'] === null);
        $this->assertSame([], $pending, 'the drain cleared every pending row, campaign finalises next tick');
    }

    public function test_delete_removes_campaign_and_recipient_history(): void
    {
        $this->createActiveClient('a@example.test');
        $campaignId = $this->sendCampaign('Disposable', '<p>Body</p>');
        $this->assertCount(1, $this->campaigns->recipients($campaignId));

        $this->assertTrue($this->service->delete($campaignId));
        $this->assertNull($this->campaigns->find($campaignId));
        $this->assertSame([], $this->campaigns->recipients($campaignId));

        $remaining = $this->db->selectOne('SELECT COUNT(*) AS c FROM mail_campaigns');
        $this->assertSame(0, (int) $remaining['c']);
    }

    public function test_delete_removes_a_sent_campaign_with_multiple_rounds(): void
    {
        $this->createActiveClient('a@example.test');
        $campaignId = $this->sendCampaign('Resent then deleted', '<p>Body</p>');

        $this->createActiveClient('b@example.test');
        $this->captureCampaignMailer();
        $this->service->resend($campaignId);
        $this->service->dispatchQueued(500);
        $this->assertCount(3, $this->campaigns->recipients($campaignId));

        $this->assertTrue($this->service->delete($campaignId));
        $this->assertSame([], $this->campaigns->recipients($campaignId), 'every round goes with the campaign');
    }

    public function test_delete_rejects_a_sending_campaign(): void
    {
        $this->createActiveClient('a@example.test');
        $campaignId = $this->campaigns->create('In flight', '<p>Body</p>', null);
        $this->service->queue($campaignId);

        $this->assertFalse($this->service->delete($campaignId), 'pause first — the cron may be draining it right now');
        $this->assertNotNull($this->campaigns->find($campaignId));
    }

    public function test_delete_removes_a_paused_campaign(): void
    {
        $this->createActiveClient('a@example.test');
        $campaignId = $this->campaigns->create('Paused forever', '<p>Body</p>', null);
        $this->service->queue($campaignId);
        $this->service->pause($campaignId);

        $this->assertTrue($this->service->delete($campaignId));
        $this->assertNull($this->campaigns->find($campaignId));
    }

    /**
     * Seeds one product (in its own group) and returns its id.
     */
    private function createProduct(string $name): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $groupId = (int) $this->db->insert(
            'INSERT INTO product_groups (name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name . ' group', 0, $now, $now]
        );

        return (int) $this->db->insert(
            'INSERT INTO products (product_group_id, name, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$groupId, $name, 'active', 0, $now, $now]
        );
    }

    /**
     * Gives $clientId a service of $productId in the given status.
     */
    private function attachService(int $clientId, int $productId, string $status = 'active'): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->insert(
            'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $productId, 'Seeded service', 'monthly', 9.99, $status, (new \DateTimeImmutable('+1 month'))->format('Y-m-d'), $now, $now]
        );
    }

    public function test_products_target_queues_only_clients_who_hold_the_product(): void
    {
        $webId = $this->createProduct('Web Hosting');
        $vpsId = $this->createProduct('VPS');

        $holder = $this->createActiveClient('holder@example.test');
        $this->attachService($holder, $webId, 'active');

        $suspended = $this->createActiveClient('suspended@example.test');
        $this->attachService($suspended, $webId, 'suspended');

        $cancelled = $this->createActiveClient('cancelled@example.test');
        $this->attachService($cancelled, $webId, 'cancelled');

        $pending = $this->createActiveClient('pending@example.test');
        $this->attachService($pending, $webId, 'pending');

        $otherProduct = $this->createActiveClient('other@example.test');
        $this->attachService($otherProduct, $vpsId, 'active');

        $noService = $this->createActiveClient('noservice@example.test');

        // Holds the product but the ACCOUNT is inactive — not mailable.
        $this->clients->create([
            'email' => 'inactiveacct@example.test',
            'password' => 'secret123',
            'first_name' => 'Test',
            'last_name' => 'Client',
            'status' => 'inactive',
        ]);
        $inactiveAccountId = (int) $this->db->selectOne(
            "SELECT id FROM clients WHERE email = 'inactiveacct@example.test'"
        )['id'];
        $this->attachService($inactiveAccountId, $webId, 'active');

        $campaignId = $this->campaigns->create('Product news', '<p>Hi</p>', null, null, null, false, (string) $webId);

        $this->assertSame(2, $this->service->queue($campaignId), 'only the active and suspended holders qualify');

        $queuedEmails = array_map(
            static fn (array $r): string => (string) $r['email'],
            $this->campaigns->recipients($campaignId)
        );
        sort($queuedEmails);
        $this->assertSame(
            ['holder@example.test', 'suspended@example.test'],
            $queuedEmails,
            'cancelled/pending services, other products, no service and inactive accounts are all excluded'
        );
    }

    public function test_products_target_accepts_multiple_products_and_resolves_fresh_on_resend(): void
    {
        $webId = $this->createProduct('Web Hosting');
        $vpsId = $this->createProduct('VPS');

        $webClient = $this->createActiveClient('web@example.test');
        $this->attachService($webClient, $webId, 'active');

        $mailer = $this->captureCampaignMailer();

        // Multi-product targeting, normalised the way the controller stores it.
        $campaignId = $this->campaigns->create('Bundle news', '<p>Hi</p>', null, null, null, false, $webId . ',' . $vpsId);
        $this->assertSame(1, $this->service->queue($campaignId), 'only the web holder exists so far');
        $this->service->dispatchQueued(100);
        $this->assertSame('sent', $this->campaigns->find($campaignId)['status']);

        // Later, a new client buys the VPS. The audience is resolved at send
        // time, so a resend reaches them without editing anything.
        $vpsClient = $this->createActiveClient('vps@example.test');
        $this->attachService($vpsClient, $vpsId, 'active');

        $this->assertSame(1, $this->service->resend($campaignId), 'resend resolves the product audience fresh');
        $this->assertCount(1, $mailer->sent);
        $this->assertSame('vps@example.test', $mailer->sent[0]['to']);
    }

    public function test_audience_edit_can_switch_to_and_from_products_targeting(): void
    {
        $webId = $this->createProduct('Web Hosting');
        $groupId = $this->groups->create('VIP');

        $campaignId = $this->campaigns->create('Group campaign', '<p>Hi</p>', $groupId);

        // Switch the audience to products.
        $this->assertTrue($this->service->update($campaignId, 'Group campaign', '<p>Hi</p>', [
            'client_group_id' => null,
            'product_ids' => (string) $webId,
        ]));

        $campaign = $this->campaigns->find($campaignId);
        $this->assertSame((string) $webId, $campaign['product_ids']);
        $this->assertNull($campaign['client_group_id'], 'switching target types clears the previous one');

        // And back to a group — the product list must be cleared, not linger.
        $this->assertTrue($this->service->update($campaignId, 'Group campaign', '<p>Hi</p>', [
            'client_group_id' => $groupId,
        ]));

        $campaign = $this->campaigns->find($campaignId);
        $this->assertSame($groupId, (int) $campaign['client_group_id']);
        $this->assertSame('', (string) ($campaign['product_ids'] ?? ''), 'the product list is cleared with the rest of the old targeting');
    }
}
