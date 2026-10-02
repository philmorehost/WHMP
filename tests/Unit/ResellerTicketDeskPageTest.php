<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Reseller\ClientResellerTicketController;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerTicketService;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;

/**
 * What the store's support desk actually renders, and the one thing it must refuse.
 *
 * THE TEST THAT MATTERS MOST is the conversation's author labels. A reply is recorded
 * as 'reseller' when the store writes it and 'admin' when WE do; the platform's own
 * ticket page treats both as support, which is correct for the status transition and
 * useless to a reseller — "has our support answered yet?" is the entire question this
 * page exists to answer. If the two render alike, a store waits forever for a reply
 * that is already sitting in front of it.
 *
 * The other tests are the ordinary page-render guards: no PHP diagnostics (a warning
 * printed before a header() turns a missing variable into a broken page), empty states
 * that do not claim all-clear, and the controller refusing a ticket id belonging to
 * another store — the point at which an IDOR would be introduced, since the id comes
 * from the URL.
 */
final class ResellerTicketDeskPageTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private ResellerStoreRepository $stores;
    private TicketRepository $tickets;
    private TicketReplyRepository $replies;
    private ResellerTicketService $service;
    private ClientAuthGuard $guard;
    private ClientResellerTicketController $controller;
    private View $view;

    private int $ownerId;
    private int $customerId;
    private int $storeId;
    private int $departmentId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->stores = new ResellerStoreRepository($this->db);
        $this->tickets = new TicketRepository($this->db);
        $this->replies = new TicketReplyRepository($this->db);

        $this->ownerId = $this->clients->create([
            'email' => 'store-owner-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Ada',
            'last_name' => 'Owner',
        ]);

        $this->customerId = $this->clients->create([
            'email' => 'shopper-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Sam',
            'last_name' => 'Shopper',
        ]);

        $this->storeId = $this->stores->create($this->ownerId, 'acme-' . substr(uniqid(), -6), 'Acme Hosting');

        // The real attribution path: the store owns the accounts it created. This is
        // what makes TicketRepository::create() derive tickets.reseller_id at all.
        $this->clients->setResellerIfUnclaimed($this->customerId, $this->storeId);

        $this->departmentId = (new DepartmentRepository($this->db))->create('Support', null);

        $this->service = new ResellerTicketService(
            $this->stores,
            $this->tickets,
            $this->replies,
            new TicketService(
                $this->tickets,
                $this->replies,
                new HookDispatcher(),
                new TicketAttachmentRepository($this->db)
            )
        );

        $configDir = sys_get_temp_dir() . '/codevault-desk-test-' . uniqid();
        mkdir($configDir);

        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $this->guard = new ClientAuthGuard($session, $this->clients);
        // Seed the session key rather than calling login(), which
        // session_regenerate_id()s and warns in CLI (no active session).
        $_SESSION['client_id'] = $this->ownerId;

        // The pages render forms, so csrf_field() resolves the token from the
        // application container. Pointing Database/SettingsRepository at the test
        // database keeps a render from reaching for the application's own DB, which
        // is a different user and database.
        $container = new Container();
        $container->instance(SessionManager::class, $session);
        $container->instance(Database::class, $this->db);
        $container->instance(SettingsRepository::class, new SettingsRepository($this->db));
        App::setContainer($container);

        $this->view = new View(dirname(__DIR__, 2) . '/resources/views');
        $this->controller = new ClientResellerTicketController($this->guard, $this->view, $session, $this->service);
    }

    // -------------------------------------------------------------- helpers ---

    private function openTicket(int $clientId, string $subject = 'Cannot send email'): int
    {
        return $this->tickets->create([
            'client_id' => $clientId,
            'email' => 'shopper@example.test',
            'department_id' => $this->departmentId,
            'subject' => $subject,
            'status' => 'open',
            'priority' => 'medium',
        ]);
    }

    /**
     * Render a view with the same data the controller passes, collecting any PHP
     * diagnostics on the way.
     *
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<int, string>}
     */
    private function render(string $template, array $data): array
    {
        $diagnostics = [];

        set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        try {
            $html = $this->view->render($template, $data);
        } finally {
            restore_error_handler();
        }

        return [$html, $diagnostics];
    }

    /** @return array<string, mixed> */
    private function store(): array
    {
        $store = $this->stores->find($this->storeId);
        $this->assertNotNull($store, 'the fixture store must exist');

        return $store;
    }

    // ------------------------------------------------------------- the list ---

    public function test_the_ticket_list_shows_the_stores_own_tickets(): void
    {
        $this->openTicket($this->customerId, 'Cannot send email');

        [$html, $diagnostics] = $this->render('reseller.client-tickets', [
            'store' => $this->store(),
            'tickets' => $this->service->ticketsForClient($this->ownerId),
            'counts' => ['total' => 1, 'needsReply' => 1, 'escalated' => 0, 'closed' => 0],
            'notice' => null,
            'error' => null,
        ]);

        $this->assertSame([], $diagnostics, 'The ticket list must render without PHP diagnostics.');
        $this->assertStringContainsString('Cannot send email', $html);
        $this->assertStringContainsString('Sam Shopper', $html);
        $this->assertStringContainsString('Acme Hosting', $html, 'The desk must be branded as the store, not as us.');
        $this->assertStringContainsString('/client/reseller/tickets/', $html);
    }

    public function test_a_store_with_no_tickets_is_told_so_rather_than_shown_an_empty_table(): void
    {
        [$html, $diagnostics] = $this->render('reseller.client-tickets', [
            'store' => $this->store(),
            'tickets' => [],
            'counts' => ['total' => 0, 'needsReply' => 0, 'escalated' => 0, 'closed' => 0],
            'notice' => null,
            'error' => null,
        ]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('Nothing here yet', $html);
        $this->assertStringNotContainsString('cv-table', $html, 'An empty queue should not render a table header with no rows.');
    }

    // --------------------------------------------- the property that matters ---

    public function test_the_conversation_distinguishes_our_reply_from_the_stores_own(): void
    {
        $ticketId = $this->openTicket($this->customerId);

        $this->replies->create($ticketId, 'client', $this->customerId, 'Sam Shopper', 'My mail is bouncing.');
        $this->replies->create($ticketId, 'reseller', $this->ownerId, 'Acme Hosting', 'I have re-checked the DNS.');
        $this->replies->create($ticketId, 'admin', 1, 'Support Agent', 'The relay was blocked; fixed.');

        [$html, $diagnostics] = $this->render('reseller.client-ticket', [
            'store' => $this->store(),
            'ticket' => $this->service->ticketForClient($this->ownerId, $ticketId),
            'replies' => $this->service->repliesForClient($this->ownerId, $ticketId),
            'authorName' => 'Acme Hosting',
            'notice' => null,
            'error' => null,
        ]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('My mail is bouncing.', $html);
        $this->assertStringContainsString('I have re-checked the DNS.', $html);
        $this->assertStringContainsString('The relay was blocked; fixed.', $html);

        // Exactly ONE reply is marked as ours. Asserting the count, not just presence,
        // is what makes this a test of DISTINGUISHABILITY: if all three labels
        // collapsed to one, this number would be 3 and the store could not tell which
        // answer released the escalation.
        $this->assertSame(
            1,
            substr_count($html, 'From our support'),
            'Exactly the platform\'s reply must be marked as coming from us.'
        );

        // And the store's own reply is marked as theirs, not ours.
        $this->assertStringContainsString('You (Acme Hosting)', $html);
    }

    public function test_an_escalated_ticket_shows_the_note_and_a_way_to_take_it_back(): void
    {
        $ticketId = $this->openTicket($this->customerId);
        $escalated = $this->service->escalate($this->ownerId, $ticketId, 'Rebuilt the mailbox, still failing.');
        $this->assertTrue($escalated['success'], 'the fixture must escalate cleanly: ' . (string) $escalated['error']);

        [$html, $diagnostics] = $this->render('reseller.client-ticket', [
            'store' => $this->store(),
            'ticket' => $this->service->ticketForClient($this->ownerId, $ticketId),
            'replies' => [],
            'authorName' => 'Acme Hosting',
            'notice' => null,
            'error' => null,
        ]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('Rebuilt the mailbox, still failing.', $html, 'The note we were given must be shown back.');
        $this->assertStringContainsString(
            '/client/reseller/tickets/' . $ticketId . '/withdraw',
            $html,
            'An escalated ticket must offer a way to withdraw the request.'
        );
        // The escalate form must be gone, or pressing it would just report "already passed".
        $this->assertStringNotContainsString('/escalate', $html);
    }

    // ------------------------------------------------------- the admin queue ---

    public function test_the_platform_queue_shows_the_store_waiting_time_and_the_note(): void
    {
        $ticketId = $this->openTicket($this->customerId, 'Mailbox quota wrong');
        $this->service->escalate($this->ownerId, $ticketId, 'Quota shows 10GB, should be 30GB.');

        $queue = $this->service->escalatedToPlatform();

        [$html, $diagnostics] = $this->render('reseller.admin-escalations', [
            'queue' => $queue,
            'waiting' => [(int) $ticketId => 50],
            'oldest' => (string) ($queue[0]['escalated_at'] ?? ''),
            'notice' => null,
            'error' => null,
        ]);

        $this->assertSame([], $diagnostics, 'The queue must render without PHP diagnostics.');
        $this->assertStringContainsString('Acme Hosting', $html);
        $this->assertStringContainsString('Quota shows 10GB, should be 30GB.', $html);
        $this->assertStringContainsString('50h', $html, 'How long it has waited is the point of the queue.');
        $this->assertStringContainsString('/admin/tickets/' . $ticketId, $html, 'Every row must lead to the full ticket page.');
    }

    public function test_an_empty_queue_says_nothing_is_waiting_rather_than_rendering_a_table(): void
    {
        [$html, $diagnostics] = $this->render('reseller.admin-escalations', [
            'queue' => [],
            'waiting' => [],
            'oldest' => null,
            'notice' => null,
            'error' => null,
        ]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('Nothing is waiting on us', $html);
        $this->assertStringNotContainsString('cv-table', $html);
    }

    // ------------------------------------------------------------ the refusal ---

    public function test_the_controller_refuses_a_ticket_belonging_to_another_store(): void
    {
        // A second store with its own owner, customer and ticket. This is the shape an
        // IDOR would take: the ticket id comes from the URL, so the only thing standing
        // between the two stores is the scoping inside the query.
        $otherOwnerId = $this->clients->create([
            'email' => 'other-owner-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Bob',
            'last_name' => 'Other',
        ]);

        $otherCustomerId = $this->clients->create([
            'email' => 'other-shopper-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Eve',
            'last_name' => 'Elsewhere',
        ]);

        $otherStoreId = $this->stores->create($otherOwnerId, 'other-' . substr(uniqid(), -6), 'Other Hosting');
        $this->clients->setResellerIfUnclaimed($otherCustomerId, $otherStoreId);

        $otherTicketId = $this->openTicket($otherCustomerId, 'Another store\'s private problem');

        // The guard is still the FIRST store's owner.
        $response = $this->controller->show(
            new Request([], [], ['REQUEST_METHOD' => 'GET'], []),
            ['ticketId' => (string) $otherTicketId]
        );

        $this->assertSame(302, $response->status(), 'Reading another store\'s ticket must redirect, not render.');
        $this->assertStringContainsString('/client/reseller/tickets', (string) ($response->headers()['Location'] ?? ''));
        $this->assertStringNotContainsString('Another store\'s private problem', $response->body());
    }

    public function test_the_controller_refuses_to_reply_on_another_stores_ticket(): void
    {
        // The write path is the one that could put words in another store's mouth.
        $otherOwnerId = $this->clients->create([
            'email' => 'other-owner2-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Bob',
            'last_name' => 'Other',
        ]);

        $otherCustomerId = $this->clients->create([
            'email' => 'other-shopper2-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Eve',
            'last_name' => 'Elsewhere',
        ]);

        $otherStoreId = $this->stores->create($otherOwnerId, 'other2-' . substr(uniqid(), -6), 'Other Hosting 2');
        $this->clients->setResellerIfUnclaimed($otherCustomerId, $otherStoreId);

        $otherTicketId = $this->openTicket($otherCustomerId, 'Not mine to answer');

        $this->controller->reply(
            new Request([], ['message' => 'A reply from the wrong store.'], ['REQUEST_METHOD' => 'POST'], []),
            ['ticketId' => (string) $otherTicketId]
        );

        $this->assertCount(
            0,
            $this->replies->forTicket($otherTicketId, true),
            'A store must not be able to write a reply onto another store\'s ticket.'
        );
    }
}
