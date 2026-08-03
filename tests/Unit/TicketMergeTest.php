<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;
use CodeVault\Tests\Support\DatabaseTestCase;

final class TicketMergeTest extends DatabaseTestCase
{
    private TicketRepository $tickets;
    private TicketReplyRepository $replies;
    private TicketAttachmentRepository $attachments;
    private TicketService $service;
    private ClientRepository $clients;
    private int $departmentId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->tickets = new TicketRepository($this->db);
        $this->replies = new TicketReplyRepository($this->db);
        $this->attachments = new TicketAttachmentRepository($this->db);
        $this->service = new TicketService($this->tickets, $this->replies, new HookDispatcher(), $this->attachments);

        $this->clients = new ClientRepository($this->db);
        $this->departmentId = (new DepartmentRepository($this->db))->create('Support', null);
    }

    private function openTicket(?int $clientId, string $email, string $subject): int
    {
        return $this->tickets->create([
            'client_id' => $clientId,
            'email' => $email,
            'department_id' => $this->departmentId,
            'subject' => $subject,
        ]);
    }

    public function test_merge_moves_replies_and_attachments_onto_the_target_and_closes_the_source(): void
    {
        $clientId = $this->clients->create(['email' => 'a@example.test', 'password' => 'secret123', 'first_name' => 'A', 'last_name' => 'One']);

        $sourceId = $this->openTicket($clientId, 'a@example.test', 'Duplicate ticket');
        $this->replies->create($sourceId, 'client', $clientId, 'A One', 'First message on the duplicate');

        $targetId = $this->openTicket($clientId, 'a@example.test', 'Original ticket');
        $this->replies->create($targetId, 'client', $clientId, 'A One', 'First message on the original');

        $this->attachments->create($sourceId, null, 'client', 'screenshot.png', 'stored123.png', 'image/png', 1024);

        $result = $this->service->merge($sourceId, $targetId, 1, 'Admin Person');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['crossClient']);

        $source = $this->tickets->find($sourceId);
        $this->assertSame($targetId, (int) $source['merged_into_id']);
        $this->assertSame('closed', $source['status']);

        // Every reply — both the pre-existing ones and the merge marker note
        // — must now live on the target, none left behind on the source.
        $sourceReplies = $this->replies->forTicket($sourceId, includePrivate: true);
        $this->assertCount(0, $sourceReplies);

        $targetReplies = $this->replies->forTicket($targetId, includePrivate: true);
        $this->assertCount(3, $targetReplies); // original message + merge note + moved-in message
        $messages = array_column($targetReplies, 'message');
        $this->assertContains('First message on the duplicate', $messages);
        $this->assertContains('First message on the original', $messages);

        $targetAttachments = $this->attachments->forTicket($targetId);
        $this->assertCount(1, $targetAttachments);
        $this->assertSame('screenshot.png', $targetAttachments[0]['original_name']);
        $this->assertCount(0, $this->attachments->forTicket($sourceId));
    }

    public function test_merge_flags_tickets_from_different_clients_as_cross_client(): void
    {
        $clientA = $this->clients->create(['email' => 'a@example.test', 'password' => 'secret123', 'first_name' => 'A', 'last_name' => 'One']);
        $clientB = $this->clients->create(['email' => 'b@example.test', 'password' => 'secret123', 'first_name' => 'B', 'last_name' => 'Two']);

        $sourceId = $this->openTicket($clientA, 'a@example.test', 'A ticket');
        $targetId = $this->openTicket($clientB, 'b@example.test', 'B ticket');

        $result = $this->service->merge($sourceId, $targetId, 1, 'Admin Person');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['crossClient']);
    }

    public function test_merge_of_same_client_tickets_is_not_flagged_cross_client(): void
    {
        $clientId = $this->clients->create(['email' => 'a@example.test', 'password' => 'secret123', 'first_name' => 'A', 'last_name' => 'One']);

        $sourceId = $this->openTicket($clientId, 'a@example.test', 'A ticket');
        $targetId = $this->openTicket($clientId, 'a@example.test', 'A other ticket');

        $result = $this->service->merge($sourceId, $targetId, 1, 'Admin Person');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['crossClient']);
    }

    public function test_merging_a_ticket_into_itself_fails(): void
    {
        $ticketId = $this->openTicket(null, 'anon@example.test', 'Solo ticket');

        $result = $this->service->merge($ticketId, $ticketId, 1, 'Admin Person');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function test_merging_an_already_merged_ticket_fails(): void
    {
        $sourceId = $this->openTicket(null, 'anon@example.test', 'A ticket');
        $targetId = $this->openTicket(null, 'anon@example.test', 'B ticket');
        $thirdId = $this->openTicket(null, 'anon@example.test', 'C ticket');

        $first = $this->service->merge($sourceId, $targetId, 1, 'Admin Person');
        $this->assertTrue($first['success']);

        $second = $this->service->merge($sourceId, $thirdId, 1, 'Admin Person');
        $this->assertFalse($second['success']);
        $this->assertStringContainsString('already merged', (string) $second['error']);
    }

    public function test_two_tickets_with_no_client_but_different_emails_are_cross_client(): void
    {
        $sourceId = $this->openTicket(null, 'first@example.test', 'A ticket');
        $targetId = $this->openTicket(null, 'second@example.test', 'B ticket');

        $result = $this->service->merge($sourceId, $targetId, 1, 'Admin Person');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['crossClient']);
    }
}
