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
use DateTimeImmutable;

/**
 * Ticket split (blueprint: merge existed, split did not). Splitting moves
 * a chosen reply and everything after it into a brand-new ticket with its
 * own subject/department; the earlier conversation stays on the original.
 */
final class TicketSplitTest extends DatabaseTestCase
{
    private TicketRepository $tickets;
    private TicketReplyRepository $replies;
    private TicketAttachmentRepository $attachments;
    private TicketService $service;
    private int $departmentId;
    private int $otherDepartmentId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->tickets = new TicketRepository($this->db);
        $this->replies = new TicketReplyRepository($this->db);
        $this->attachments = new TicketAttachmentRepository($this->db);
        $this->service = new TicketService($this->tickets, $this->replies, new HookDispatcher(), $this->attachments);

        $departments = new DepartmentRepository($this->db);
        $this->departmentId = $departments->create('Support', null);
        $this->otherDepartmentId = $departments->create('Billing', null);
    }

    private function openTicketWithReplies(int $replyCount): array
    {
        $ticketId = $this->tickets->create([
            'client_id' => null,
            'email' => 'split@example.test',
            'department_id' => $this->departmentId,
            'subject' => 'Mixed conversation',
        ]);

        $replyIds = [];
        for ($i = 1; $i <= $replyCount; $i++) {
            $replyIds[] = $this->replies->create($ticketId, 'client', null, 'Split Tester', "Reply #{$i}");
        }

        return [$ticketId, $replyIds];
    }

    public function test_split_moves_the_reply_and_everything_after_it_into_a_new_ticket(): void
    {
        [$ticketId, $replyIds] = $this->openTicketWithReplies(4);

        $result = $this->service->split($ticketId, $replyIds[1], 'New subject', $this->otherDepartmentId, 1, 'Admin Person');

        $this->assertTrue($result['success']);
        $newTicketId = (int) $result['newTicketId'];

        $newTicket = $this->tickets->find($newTicketId);
        $this->assertSame('New subject', $newTicket['subject']);
        $this->assertSame($this->otherDepartmentId, (int) $newTicket['department_id']);
        $this->assertSame('split@example.test', $newTicket['email']);

        // Replies 2, 3, 4 moved; reply 1 stays on the source. Plus the
        // split-marker private notes on both sides.
        $sourceReplies = array_column($this->replies->forTicket($ticketId, includePrivate: true), 'message');
        $newReplies = array_column($this->replies->forTicket($newTicketId, includePrivate: true), 'message');

        $this->assertContains('Reply #1', $sourceReplies);
        $this->assertNotContains('Reply #2', $sourceReplies);
        $this->assertNotContains('Reply #3', $sourceReplies);
        $this->assertNotContains('Reply #4', $sourceReplies);

        $this->assertContains('Reply #2', $newReplies);
        $this->assertContains('Reply #3', $newReplies);
        $this->assertContains('Reply #4', $newReplies);
        $this->assertNotContains('Reply #1', $newReplies);

        // Both sides carry a private note marking where the split happened.
        $this->assertCount(1, array_filter($sourceReplies, static fn (string $m): bool => str_contains($m, '— Split:')));
        $this->assertCount(1, array_filter($newReplies, static fn (string $m): bool => str_contains($m, '— Split from ticket #')));
    }

    public function test_split_carries_the_related_service_and_domain_onto_the_new_ticket(): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $clientId = (new ClientRepository($this->db))->create([
            'email' => 'split3@example.test',
            'password' => 'secret123',
            'first_name' => 'Split',
            'last_name' => 'Tester',
        ]);
        $groupId = (int) $this->db->insert(
            'INSERT INTO product_groups (name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['Split Group', 0, $now, $now]
        );
        $productId = (int) $this->db->insert(
            'INSERT INTO products (product_group_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$groupId, 'Split Product', $now, $now]
        );
        $serviceId = (int) $this->db->insert(
            "INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, 'monthly', ?, 'active', ?, ?, ?)",
            [$clientId, $productId, 'Split Product', 9.99, $now, $now, $now]
        );
        $domainId = (int) $this->db->insert(
            'INSERT INTO domains (client_id, domain_name, tld, registrar_slug, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'split-carry.test', 'test', 'local', 'active', $now, $now]
        );

        $ticketId = $this->tickets->create([
            'client_id' => $clientId,
            'email' => 'split3@example.test',
            'department_id' => $this->departmentId,
            'subject' => 'Two problems on one service',
            'service_id' => $serviceId,
            'domain_id' => $domainId,
        ]);
        $reply1 = $this->replies->create($ticketId, 'client', $clientId, 'Split Tester', 'About the hosting');
        $reply2 = $this->replies->create($ticketId, 'client', $clientId, 'Split Tester', 'Unrelated second problem');

        $result = $this->service->split($ticketId, $reply2, 'Second problem', $this->otherDepartmentId, 1, 'Admin Person');

        // Both halves are still about the same service/domain, so the new
        // ticket must not lose that context.
        $newTicket = $this->tickets->find((int) $result['newTicketId']);
        $this->assertSame($serviceId, (int) $newTicket['service_id']);
        $this->assertSame($domainId, (int) $newTicket['domain_id']);
        $this->assertSame('Split Product', $newTicket['service_product_name']);
    }

    public function test_split_moves_attachments_with_the_replies(): void
    {
        $clientId = (new ClientRepository($this->db))->create([
            'email' => 'split2@example.test',
            'password' => 'secret123',
            'first_name' => 'Split',
            'last_name' => 'Tester',
        ]);
        $ticketId = $this->tickets->create([
            'client_id' => $clientId,
            'email' => 'split2@example.test',
            'department_id' => $this->departmentId,
            'subject' => 'With attachment',
        ]);
        $reply1 = $this->replies->create($ticketId, 'client', $clientId, 'Split Tester', 'Before');
        $reply2 = $this->replies->create($ticketId, 'client', $clientId, 'Split Tester', 'After');

        // One attachment on each reply — the split must take only the moved one.
        $this->attachments->create($ticketId, $reply1, 'client', 'before.png', 'b.png', 'image/png', 100);
        $this->attachments->create($ticketId, $reply2, 'client', 'after.png', 'a.png', 'image/png', 100);

        $result = $this->service->split($ticketId, $reply2, 'Split part', $this->otherDepartmentId, 1, 'Admin Person');
        $newTicketId = (int) $result['newTicketId'];

        $sourceAttachments = $this->attachments->forTicket($ticketId);
        $newAttachments = $this->attachments->forTicket($newTicketId);

        $this->assertCount(1, $sourceAttachments);
        $this->assertSame('before.png', $sourceAttachments[0]['original_name']);
        $this->assertCount(1, $newAttachments);
        $this->assertSame('after.png', $newAttachments[0]['original_name']);
    }

    public function test_split_rejects_the_opening_message_as_the_split_point(): void
    {
        [$ticketId, $replyIds] = $this->openTicketWithReplies(3);

        $result = $this->service->split($ticketId, $replyIds[0], 'New subject', $this->otherDepartmentId, 1, 'Admin Person');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('opening message', (string) $result['error']);
    }

    public function test_split_rejects_a_reply_that_does_not_belong_to_the_ticket(): void
    {
        [$ticketId, $replyIds] = $this->openTicketWithReplies(2);
        $otherTicketId = $this->tickets->create([
            'client_id' => null,
            'email' => 'other@example.test',
            'department_id' => $this->departmentId,
            'subject' => 'Another ticket',
        ]);
        $foreignReply = $this->replies->create($otherTicketId, 'client', null, 'Split Tester', 'On another ticket');

        $result = $this->service->split($ticketId, $foreignReply, 'New subject', $this->otherDepartmentId, 1, 'Admin Person');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('does not exist', (string) $result['error']);
    }

    public function test_split_rejects_a_merged_ticket(): void
    {
        [$ticketId, $replyIds] = $this->openTicketWithReplies(2);
        $targetTicketId = $this->tickets->create([
            'client_id' => null,
            'email' => 'target@example.test',
            'department_id' => $this->departmentId,
            'subject' => 'Merge target',
        ]);
        $this->tickets->setMergedInto($ticketId, $targetTicketId);

        $result = $this->service->split($ticketId, $replyIds[1], 'New subject', $this->otherDepartmentId, 1, 'Admin Person');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('merged', (string) $result['error']);
    }
}
