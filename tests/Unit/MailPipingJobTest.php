<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\BlockedEmailSenderRepository;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\MailPipingJob;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;
use CodeVault\Tests\Fixtures\FakeMailboxClient;
use CodeVault\Tests\Support\DatabaseTestCase;

final class MailPipingJobTest extends DatabaseTestCase
{
    private SettingsRepository $settings;
    private DepartmentRepository $departments;
    private TicketRepository $tickets;
    private TicketReplyRepository $replies;
    private ClientRepository $clients;
    private TicketService $ticketService;
    private BlockedEmailSenderRepository $blockedSenders;
    private int $departmentId;
    private int $billingDepartmentId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->settings = new SettingsRepository($this->db);
        $this->departments = new DepartmentRepository($this->db);
        $this->tickets = new TicketRepository($this->db);
        $this->replies = new TicketReplyRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->ticketService = new TicketService($this->tickets, $this->replies, new HookDispatcher(), new TicketAttachmentRepository($this->db));
        $this->blockedSenders = new BlockedEmailSenderRepository($this->db);

        $this->departmentId = $this->departments->create('General Support', 'support@example.test');
        $this->billingDepartmentId = $this->departments->create('Billing', 'billing@example.test');

        $this->settings->set('mail_piping.enabled', '1');
        $this->settings->set('mail_piping.host', 'imap.example.test');
        $this->settings->set('mail_piping.username', 'support@example.test');
        $this->settings->set('mail_piping.password', 'secret');
    }

    private function job(FakeMailboxClient $mailbox): MailPipingJob
    {
        return new MailPipingJob($mailbox, $this->settings, $this->departments, $this->tickets, $this->ticketService, $this->clients, $this->blockedSenders);
    }

    public function test_disabled_setting_skips_processing_entirely(): void
    {
        $this->settings->set('mail_piping.enabled', '0');
        $mailbox = new FakeMailboxClient([
            ['uid' => 1, 'from' => 'Jane <jane@example.com>', 'to' => 'support@example.test', 'subject' => 'Help', 'body' => 'I need help.'],
        ]);

        $this->job($mailbox)->handle();

        $this->assertSame([], $this->tickets->all());
        $this->assertSame([], $mailbox->markedSeen);
    }

    public function test_new_message_creates_a_ticket_routed_by_the_to_address(): void
    {
        $mailbox = new FakeMailboxClient([
            ['uid' => 5, 'from' => 'Jane Doe <jane@example.com>', 'to' => 'billing@example.test', 'subject' => 'Invoice question', 'body' => 'Why was I charged twice?'],
        ]);

        $this->job($mailbox)->handle();

        $tickets = $this->tickets->all();
        $this->assertCount(1, $tickets);
        $this->assertSame('Invoice question', $tickets[0]['subject']);
        $this->assertSame($this->billingDepartmentId, (int) $tickets[0]['department_id']);
        $this->assertSame('jane@example.com', $tickets[0]['email']);
        $this->assertNull($tickets[0]['client_id']);
    }

    public function test_new_message_from_a_known_client_email_links_the_ticket_to_that_client(): void
    {
        $clientId = $this->clients->create([
            'email' => 'jane@example.com',
            'password' => 'secret123',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $mailbox = new FakeMailboxClient([
            ['uid' => 9, 'from' => 'jane@example.com', 'to' => 'support@example.test', 'subject' => 'Login issue', 'body' => 'Cannot log in.'],
        ]);

        $this->job($mailbox)->handle();

        $tickets = $this->tickets->all();
        $this->assertSame($clientId, (int) $tickets[0]['client_id']);
    }

    public function test_message_tagged_with_an_existing_ticket_number_is_appended_as_a_reply(): void
    {
        $ticketId = $this->ticketService->open(null, 'jane@example.com', $this->departmentId, 'Original subject', 'Jane', 'First message.');

        $mailbox = new FakeMailboxClient([
            ['uid' => 3, 'from' => 'jane@example.com', 'to' => 'support@example.test', 'subject' => "Re: Original subject [Ticket #{$ticketId}]", 'body' => 'Following up on this.'],
        ]);

        $this->job($mailbox)->handle();

        $this->assertCount(1, $this->tickets->all());
        $replies = $this->replies->forTicket($ticketId, includePrivate: false);
        $this->assertCount(2, $replies);
        $this->assertSame('Following up on this.', $replies[1]['message']);

        $ticket = $this->tickets->find($ticketId);
        $this->assertSame('customer-reply', $ticket['status']);
    }

    public function test_processed_messages_are_marked_seen(): void
    {
        $mailbox = new FakeMailboxClient([
            ['uid' => 42, 'from' => 'jane@example.com', 'to' => 'support@example.test', 'subject' => 'Help', 'body' => 'Hi.'],
        ]);

        $this->job($mailbox)->handle();

        $this->assertSame([42], $mailbox->markedSeen);
    }

    public function test_message_with_no_matching_department_falls_back_to_the_first_department(): void
    {
        // DepartmentRepository::all() orders alphabetically by name, so
        // "Billing" sorts before "General Support" — that's the fallback.
        $mailbox = new FakeMailboxClient([
            ['uid' => 7, 'from' => 'jane@example.com', 'to' => 'unknown@nowhere.test', 'subject' => 'Help', 'body' => 'Hi.'],
        ]);

        $this->job($mailbox)->handle();

        $tickets = $this->tickets->all();
        $this->assertCount(1, $tickets);
        $this->assertSame($this->billingDepartmentId, (int) $tickets[0]['department_id']);
    }

    public function test_blocked_exact_sender_does_not_create_a_ticket_but_is_marked_seen(): void
    {
        $this->blockedSenders->block('mailer-daemon@whiterider.pmhserver.name.ng');

        $mailbox = new FakeMailboxClient([
            ['uid' => 8, 'from' => 'Mailer-Daemon@whiterider.pmhserver.name.ng', 'to' => 'support@example.test', 'subject' => 'Undelivered Mail Returned to Sender', 'body' => 'The address could not be found.'],
        ]);

        $this->job($mailbox)->handle();

        $this->assertSame([], $this->tickets->all());
        $this->assertSame([8], $mailbox->markedSeen);
    }

    public function test_blocked_wildcard_domain_skips_every_bounce_sender_on_that_domain(): void
    {
        $this->blockedSenders->block('*@pmhserver.name.ng');

        $mailbox = new FakeMailboxClient([
            ['uid' => 11, 'from' => 'Mailer-Daemon@whiterider.pmhserver.name.ng', 'to' => 'support@example.test', 'subject' => 'Undelivered Mail', 'body' => 'Bounce 1.'],
            ['uid' => 12, 'from' => 'MAILER-DAEMON@pmhserver.name.ng', 'to' => 'support@example.test', 'subject' => 'Undelivered Mail', 'body' => 'Bounce 2.'],
            ['uid' => 13, 'from' => 'jane@example.com', 'to' => 'support@example.test', 'subject' => 'Help', 'body' => 'Real client message.'],
        ]);

        $this->job($mailbox)->handle();

        $tickets = $this->tickets->all();
        $this->assertCount(1, $tickets);
        $this->assertSame('jane@example.com', $tickets[0]['email']);
        $this->assertSame([11, 12, 13], $mailbox->markedSeen);
    }

    public function test_blocked_sender_cannot_reply_to_an_existing_ticket(): void
    {
        $this->ticketService->open(null, 'mailer-daemon@whiterider.pmhserver.name.ng', $this->departmentId, 'Bounce', 'Mailer-Daemon', 'Original bounce.');
        $ticketId = (int) $this->tickets->all()[0]['id'];

        $this->blockedSenders->block('mailer-daemon@whiterider.pmhserver.name.ng');

        $mailbox = new FakeMailboxClient([
            ['uid' => 14, 'from' => 'Mailer-Daemon@whiterider.pmhserver.name.ng', 'to' => 'support@example.test', 'subject' => "Re: Bounce [Ticket #{$ticketId}]", 'body' => 'Another bounce.'],
        ]);

        $this->job($mailbox)->handle();

        $this->assertCount(1, $this->replies->forTicket($ticketId, includePrivate: false));
        $this->assertSame([14], $mailbox->markedSeen);
    }
}
