<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerTicketService;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * A store's support desk: its own customers' tickets, and passing one up to us.
 *
 * Three rules here are easy to get subtly wrong, and each has a test:
 *
 *  1. **A store's reply must mark the ticket ANSWERED.** The status comes from the
 *     author type, so a store's reply recorded as anything but support sets the
 *     ticket to "customer replied" — backwards for the person who just answered.
 *  2. **A store's own reply must NOT clear its escalation.** The escalation is the
 *     store asking US for help; answering its own customer is not a reply to that
 *     request, and clearing it there drops the request while the ticket still
 *     needs us. The pair of tests below pins both halves.
 *  3. **One store must never reach another's ticket**, whichever id is handed in.
 *
 * The ticket's store is DERIVED from the client's owner rather than passed, so the
 * first test is about a ticket created with no mention of a store at all.
 */
final class ResellerTicketServiceTest extends DatabaseTestCase
{
    private ResellerTicketService $service;
    private ResellerStoreRepository $stores;
    private TicketRepository $tickets;
    private ClientRepository $clients;
    private int $storeId;
    private int $ownerId;
    private int $customerId;
    private int $otherCustomerId;
    private int $departmentId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->ownerId = $this->client('owner');
        $this->customerId = $this->client('shopper');
        $this->otherCustomerId = $this->client('stranger');

        $this->stores = new ResellerStoreRepository($this->db);
        $this->storeId = $this->stores->create($this->ownerId, 'acme-' . substr(uniqid(), -6), 'Acme Hosting');

        // The real attribution path: a store claims the accounts IT created.
        $this->assertTrue($this->clients->setResellerIfUnclaimed($this->customerId, $this->storeId));

        $this->departmentId = (new DepartmentRepository($this->db))->create('Support', null);
        $this->tickets = new TicketRepository($this->db);

        $this->service = new ResellerTicketService(
            $this->stores,
            $this->tickets,
            new TicketReplyRepository($this->db),
            new TicketService(
                $this->tickets,
                new TicketReplyRepository($this->db),
                new HookDispatcher(),
                new TicketAttachmentRepository($this->db)
            )
        );
    }

    // --- the store is DERIVED, not passed --------------------------------

    public function test_a_ticket_for_a_stores_customer_belongs_to_that_store(): void
    {
        // Note what is NOT passed: the caller never mentions a store, which is what
        // makes the portal form, admin-created tickets and mail piping all stamp it.
        $ticketId = $this->openTicket($this->customerId);

        $this->assertSame(
            $this->storeId,
            (int) $this->tickets->find($ticketId)['reseller_id'],
            'A ticket for a store-owned client must belong to that store.'
        );
    }

    public function test_a_ticket_for_an_unowned_client_stays_on_the_platforms_desk(): void
    {
        $ticketId = $this->openTicket($this->otherCustomerId);

        $this->assertNull($this->tickets->find($ticketId)['reseller_id']);
    }

    // --- the gate ---------------------------------------------------------

    public function test_one_store_cannot_reach_another_stores_ticket(): void
    {
        $mine = $this->openTicket($this->customerId);
        $theirs = $this->openTicket($this->otherCustomerId, storeId: null);

        // The store's own ticket is reachable...
        $this->assertNotNull($this->service->ticketForClient($this->ownerId, $mine));

        // ...and the unrelated one is not, even though the id is a real ticket.
        $this->assertNull($this->service->ticketForClient($this->ownerId, $theirs));
        $this->assertSame([], $this->service->repliesForClient($this->ownerId, $theirs));
        $this->assertFalse($this->service->reply($this->ownerId, $theirs, 'Hello', 'Acme')['success']);
        $this->assertFalse($this->service->escalate($this->ownerId, $theirs, 'Please help')['success']);
        $this->assertFalse($this->service->withdraw($this->ownerId, $theirs)['success']);
    }

    // --- the status trap --------------------------------------------------

    public function test_a_stores_reply_marks_the_ticket_answered_and_not_customer_reply(): void
    {
        $ticketId = $this->openTicket($this->customerId);

        $result = $this->service->reply($this->ownerId, $ticketId, 'We have replaced the disk.', 'Acme Support');

        $this->assertTrue($result['success'], (string) $result['error']);

        $ticket = $this->tickets->find($ticketId);

        $this->assertSame('answered', (string) $ticket['status'], 'A store answering its own customer is SUPPORT answering.');
        $this->assertSame('reseller', (string) $ticket['last_reply_by']);

        // And it stays distinguishable from OUR reply in the record, which is what
        // lets only our answer close an escalation.
        $replies = (new TicketReplyRepository($this->db))->forTicket($ticketId, includePrivate: false);
        $this->assertSame('reseller', (string) $replies[0]['author_type']);
    }

    // --- escalation, both halves -----------------------------------------

    public function test_escalating_needs_a_note_and_puts_the_ticket_in_the_platforms_queue(): void
    {
        $ticketId = $this->openTicket($this->customerId);

        $this->assertFalse($this->service->escalate($this->ownerId, $ticketId, '   ')['success'], 'A bare "help" wastes the platform\'s first hour.');
        $this->assertTrue($this->service->escalate($this->ownerId, $ticketId, 'Nameserver is refusing updates.')['success']);

        $queued = $this->service->escalatedToPlatform();
        $this->assertCount(1, $queued);
        $this->assertSame($ticketId, (int) $queued[0]['id']);
        $this->assertSame('Nameserver is refusing updates.', (string) $queued[0]['escalated_note']);
        $this->assertSame(1, $this->service->escalatedCount());

        // A second escalation must not overwrite the first note.
        $this->assertFalse($this->service->escalate($this->ownerId, $ticketId, 'Again')['success']);
    }

    public function test_the_stores_own_reply_does_not_clear_the_escalation(): void
    {
        $ticketId = $this->openTicket($this->customerId);
        $this->service->escalate($this->ownerId, $ticketId, 'Out of my depth.');

        $this->service->reply($this->ownerId, $ticketId, 'Still working on it.', 'Acme Support');

        $this->assertNotNull(
            $this->tickets->find($ticketId)['escalated_at'],
            'The store answering its own customer is not a reply to its own request for help.'
        );
        $this->assertSame(1, $this->service->escalatedCount());
    }

    public function test_the_platforms_reply_clears_the_escalation(): void
    {
        $ticketId = $this->openTicket($this->customerId);
        $this->service->escalate($this->ownerId, $ticketId, 'Out of my depth.');

        // What answering from the platform's own ticket page does.
        (new TicketService(
            $this->tickets,
            new TicketReplyRepository($this->db),
            new HookDispatcher(),
            new TicketAttachmentRepository($this->db)
        ))->reply($ticketId, 'admin', null, 'Support', 'Fixed the zone.');

        $this->assertNull($this->tickets->find($ticketId)['escalated_at']);
        $this->assertSame(0, $this->service->escalatedCount(), 'An answered escalation leaves the queue.');
    }

    public function test_the_store_can_take_an_escalation_back(): void
    {
        $ticketId = $this->openTicket($this->customerId);
        $this->service->escalate($this->ownerId, $ticketId, 'Out of my depth.');

        $this->assertTrue($this->service->withdraw($this->ownerId, $ticketId)['success']);
        $this->assertNull($this->tickets->find($ticketId)['escalated_at']);

        // Nothing left to withdraw.
        $this->assertFalse($this->service->withdraw($this->ownerId, $ticketId)['success']);
    }

    // --- the schema choice ------------------------------------------------

    public function test_deleting_a_store_returns_its_tickets_to_the_platform(): void
    {
        $ticketId = $this->openTicket($this->customerId);
        $this->service->reply($this->ownerId, $ticketId, 'On it.', 'Acme Support');

        // No repository delete() exists — a store is removed with its owner — so
        // the row goes directly, which is all the foreign key needs to act on.
        $this->assertSame(1, $this->db->delete('DELETE FROM resellers WHERE id = ?', [$this->storeId]));
        $ticket = $this->tickets->find($ticketId);

        // SET NULL, not CASCADE: a conversation with a customer is a record with its
        // own value. Deleting a store must not delete what was said to the customer.
        $this->assertNull($ticket['reseller_id'], 'The ticket survives, and returns to the platform.');
        $this->assertSame('answered', (string) $ticket['status'], 'The conversation is intact, status and all.');
    }

    // --- helpers ----------------------------------------------------------

    private function client(string $tag): int
    {
        return $this->clients->create([
            'email' => $tag . '-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'first_name' => ucfirst($tag),
            'last_name' => 'Test',
        ]);
    }

    /** Opened through the repository exactly as the portal form does it. */
    private function openTicket(int $clientId, ?int $storeId = null): int
    {
        return $this->tickets->create([
            'client_id' => $clientId,
            'reseller_id' => $storeId,
            'email' => 'customer-' . $clientId . '@example.test',
            'department_id' => $this->departmentId,
            'subject' => 'Disk failed',
            'message' => 'Please help',
        ]);
    }
}
