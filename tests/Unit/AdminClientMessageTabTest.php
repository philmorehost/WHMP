<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Ai\AiProvider;
use CodeVault\Ai\AiSettings;
use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\VatLookupService;
use CodeVault\Billing\ViesVatLookupService;
use CodeVault\Clients\ClientController;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\CpanelTools\CpanelUapiClient;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Queue\QueueInterface;
use CodeVault\Queue\SyncQueue;
use CodeVault\Request;
use CodeVault\Reseller\ResellerDomainProvisioner;
use CodeVault\Reseller\ResellerDomainSync;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\RoleRepository;
use CodeVault\Support\App;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Tests\Fixtures\FakeAiProvider;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;
use DateTimeImmutable;

/**
 * The client account page's Message tab.
 *
 * Two things are pinned here. The feature: the admin can see this client's
 * tickets with their status, reply to one without leaving the page, and use
 * "AI: Generate reply" / "AI: Refine my draft". And the gate: every one of
 * those actions takes a ticket id from the URL, so each must refuse a ticket
 * that belongs to a different client — otherwise the tab would render someone
 * else's conversation and the reply POST would answer their ticket.
 */
final class AdminClientMessageTabTest extends DatabaseTestCase
{
    private ClientController $controller;
    private ClientRepository $clients;
    private TicketRepository $tickets;
    private TicketReplyRepository $replies;
    private SettingsRepository $settings;
    private FakeAiProvider $ai;
    private int $adminId;
    private int $clientId;
    private int $otherClientId;
    private int $departmentId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // Migration 0013 seeds a super-admin role, which bypasses the
        // permission matrix — the controller gates every action on
        // CLIENTS_VIEW / CLIENTS_MANAGE, so the admin must actually hold it.
        $roleId = (int) $this->db->selectOne('SELECT id FROM roles WHERE is_super_admin = 1 ORDER BY id LIMIT 1')['id'];
        $this->adminId = (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['msgadmin', 'msgadmin@example.test', password_hash('correct-horse-battery', PASSWORD_ARGON2ID), 'Msg Admin', $roleId, $now, $now]
        );

        $this->clients = new ClientRepository($this->db);
        $this->tickets = new TicketRepository($this->db);
        $this->replies = new TicketReplyRepository($this->db);
        $this->settings = new SettingsRepository($this->db);

        $this->clientId = $this->clients->create([
            'email' => 'messageowner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Message',
            'last_name' => 'Owner',
        ]);
        $this->otherClientId = $this->clients->create([
            'email' => 'otherparty@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Other',
            'last_name' => 'Party',
        ]);

        $this->departmentId = (new DepartmentRepository($this->db))->create('Support', null);

        $configDir = sys_get_temp_dir() . '/codevault-message-tab-test-' . uniqid();
        mkdir($configDir);
        $config = new Config($configDir);

        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $admins = new AdminRepository($this->db);
        $guard = new AuthGuard($session, $admins, new RoleRepository($this->db));
        // Seed the guard's own session key rather than calling login(), which
        // session_regenerate_id()s and warns in CLI (no active session).
        $_SESSION['admin_id'] = $this->adminId;

        $this->ai = new FakeAiProvider();

        // The controller has ~25 dependencies, all resolvable from the database
        // except the few pinned here. Building it through the container keeps
        // this test from breaking every time a dependency is added.
        $container = new Container();
        $container->instance(Database::class, $this->db);
        $container->instance(Config::class, $config);
        $container->instance(SettingsRepository::class, $this->settings);
        $container->instance(SessionManager::class, $session);
        $container->instance(View::class, new View(dirname(__DIR__, 2) . '/resources/views'));
        $container->instance(AiProvider::class, $this->ai);
        $container->instance(AiSettings::class, new AiSettings($this->settings, $config));
        $container->instance(QueueInterface::class, new SyncQueue());
        // VatLookupService is an interface (Kernel binds it to the VIES client),
        // so a container that isn't the Kernel's has to bind it here.
        $container->instance(VatLookupService::class, new ViesVatLookupService(new FakeHttpClient()));
        // Same reason: the store-domain sync reached from ClientController::delete()
        // bottoms out at the HttpClient INTERFACE, which a bare container cannot
        // instantiate. Provisioning is off by default, so this fake is never called.
        $container->instance(
            ResellerDomainSync::class,
            new ResellerDomainSync(
                new ResellerStoreRepository($this->db),
                new ResellerDomainProvisioner(
                    new CpanelUapiClient(new FakeHttpClient()),
                    new ServerRepository($this->db),
                    $this->settings
                )
            )
        );
        // csrf_field() in the rendered view resolves CsrfToken through App.
        $container->bind(CsrfToken::class);
        App::setContainer($container);

        $this->controller = $container->make(ClientController::class);
    }

    // --- the feature -----------------------------------------------------

    public function test_the_message_tab_lists_the_clients_tickets_with_their_status(): void
    {
        $ticketId = $this->ticketFor($this->clientId, 'Cannot send email');
        $foreignId = $this->ticketFor($this->otherClientId, 'Someone elses problem');

        $body = $this->showMessageTab();

        $this->assertStringContainsString('Cannot send email', $body);
        $this->assertStringContainsString('Support Tickets (1)', $body);
        // The status is shown, and links through to the ticket.
        $this->assertStringContainsString('/admin/tickets/' . $ticketId, $body);
        // Another client's ticket must not appear on this page at all.
        $this->assertStringNotContainsString('Someone elses problem', $body);
        $this->assertStringNotContainsString('/admin/tickets/' . $foreignId, $body);
    }

    public function test_a_reply_from_the_message_tab_is_recorded_and_answers_the_ticket(): void
    {
        $ticketId = $this->ticketFor($this->clientId, 'Needs an answer');

        $response = $this->controller->replyToTicket(
            $this->post(['message' => 'Here is your answer.']),
            ['id' => (string) $this->clientId, 'ticketId' => (string) $ticketId]
        );

        $this->assertSame(302, $response->status());

        $messages = array_column($this->replies->forTicket($ticketId, includePrivate: true), 'message');
        $this->assertContains('Here is your answer.', $messages);

        // TicketService::reply() is what moves the status — a direct insert into
        // the reply table would have left this ticket "open" and the client's
        // portal none the wiser.
        $ticket = $this->tickets->find($ticketId);
        $this->assertSame('answered', $ticket['status']);
        $this->assertNotNull($ticket['last_reply_at']);
        $this->assertSame('admin', $ticket['last_reply_by']);
    }

    public function test_an_empty_reply_is_refused(): void
    {
        $ticketId = $this->ticketFor($this->clientId, 'Empty reply');

        $response = $this->controller->replyToTicket(
            $this->post(['message' => '   ']),
            ['id' => (string) $this->clientId, 'ticketId' => (string) $ticketId]
        );

        $this->assertSame(302, $response->status());
        $this->assertSame([], $this->replies->forTicket($ticketId, includePrivate: true));
    }

    public function test_ai_draft_puts_the_generated_reply_in_front_of_the_admin(): void
    {
        $ticketId = $this->ticketFor($this->clientId, 'Where is my invoice?');
        $this->replies->create($ticketId, 'client', $this->clientId, 'Message Owner', 'I still have not received it.');
        $this->ai->respondWith(true, 'It is attached to your account, and mailed to you.');

        $response = $this->controller->aiDraftTicketReply(
            $this->post([]),
            ['id' => (string) $this->clientId, 'ticketId' => (string) $ticketId]
        );

        $this->assertSame(302, $response->status());
        $this->assertCount(1, $this->ai->calls);

        // The provider is given the transcript, then the suggested text is
        // rendered back on the page for the admin to edit and send.
        $this->assertStringContainsString('I still have not received it.', (string) $this->ai->lastCall()['user']);

        $body = $this->showMessageTab($ticketId);
        $this->assertStringContainsString('AI-suggested reply', $body);
        $this->assertStringContainsString('It is attached to your account', $body);
    }

    public function test_ai_refine_sends_the_admins_own_draft_to_the_provider(): void
    {
        $ticketId = $this->ticketFor($this->clientId, 'Refine me');
        $this->ai->respondWith(true, 'A polished, professional reply.');

        $response = $this->controller->aiRefineTicketReply(
            $this->post(['message' => 'yeah fixed it, ok thx']),
            ['id' => (string) $this->clientId, 'ticketId' => (string) $ticketId]
        );

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('yeah fixed it, ok thx', (string) $this->ai->lastCall()['user']);
        $this->assertStringContainsString('rewrite', strtolower((string) $this->ai->lastCall()['system']));

        $body = $this->showMessageTab($ticketId);
        $this->assertStringContainsString('AI-refined draft', $body);
        $this->assertStringContainsString('A polished, professional reply.', $body);
    }

    public function test_ai_refine_needs_a_draft_to_work_from(): void
    {
        $ticketId = $this->ticketFor($this->clientId, 'Nothing typed');

        $this->controller->aiRefineTicketReply(
            $this->post(['message' => '  ']),
            ['id' => (string) $this->clientId, 'ticketId' => (string) $ticketId]
        );

        $this->assertCount(0, $this->ai->calls, 'an empty draft must not be sent to the provider');
        $this->assertStringContainsString('nothing to refine', $this->showMessageTab($ticketId));
    }

    public function test_ai_draft_is_refused_when_the_feature_is_switched_off(): void
    {
        $ticketId = $this->ticketFor($this->clientId, 'Feature off');
        $this->settings->set('ai.feature.ticket_replies', '0');

        $this->controller->aiDraftTicketReply(
            $this->post([]),
            ['id' => (string) $this->clientId, 'ticketId' => (string) $ticketId]
        );

        $this->assertCount(0, $this->ai->calls, 'a disabled feature must not call the provider');
        $this->assertStringContainsString('turned off', $this->showMessageTab($ticketId));
    }

    public function test_a_provider_failure_is_reported_instead_of_rendering_a_blank_box(): void
    {
        $ticketId = $this->ticketFor($this->clientId, 'Provider down');
        $this->ai->respondWith(false, null, 'Quota exceeded.');

        $this->controller->aiDraftTicketReply(
            $this->post([]),
            ['id' => (string) $this->clientId, 'ticketId' => (string) $ticketId]
        );

        $this->assertStringContainsString('Quota exceeded.', $this->showMessageTab($ticketId));
    }

    // --- the gate --------------------------------------------------------

    public function test_a_reply_cannot_be_posted_onto_another_clients_ticket(): void
    {
        $foreignId = $this->ticketFor($this->otherClientId, 'Not your ticket');

        $response = $this->controller->replyToTicket(
            $this->post(['message' => 'Replying to the wrong client']),
            ['id' => (string) $this->clientId, 'ticketId' => (string) $foreignId]
        );

        $this->assertSame(404, $response->status());
        $this->assertSame(
            [],
            $this->replies->forTicket($foreignId, includePrivate: true),
            'no reply may be written to a ticket belonging to another client'
        );
    }

    public function test_the_reply_panel_will_not_open_another_clients_ticket(): void
    {
        $foreignId = $this->ticketFor($this->otherClientId, 'Not your ticket');
        $this->replies->create($foreignId, 'client', $this->otherClientId, 'Other Party', 'Something confidential.');

        $body = $this->showMessageTab($foreignId);

        $this->assertStringNotContainsString('Something confidential.', $body, 'another client\'s conversation must not be rendered');
        $this->assertStringNotContainsString("tickets/{$foreignId}/reply", $body, 'no reply form may be rendered for it');
    }

    public function test_the_ai_actions_refuse_another_clients_ticket(): void
    {
        $foreignId = $this->ticketFor($this->otherClientId, 'Not your ticket');

        $draft = $this->controller->aiDraftTicketReply(
            $this->post([]),
            ['id' => (string) $this->clientId, 'ticketId' => (string) $foreignId]
        );
        $refine = $this->controller->aiRefineTicketReply(
            $this->post(['message' => 'text']),
            ['id' => (string) $this->clientId, 'ticketId' => (string) $foreignId]
        );

        $this->assertSame(404, $draft->status());
        $this->assertSame(404, $refine->status());
        $this->assertCount(0, $this->ai->calls, 'no provider call may be made for another client\'s ticket');
    }

    // --- the billing tab -------------------------------------------------

    public function test_the_billing_tab_lists_invoices_with_a_view_link_and_their_own_status(): void
    {
        $paid = $this->invoice(150.00, 'paid');
        $cancelled = $this->invoice(75.00, 'cancelled');

        $body = $this->controller
            ->show(new Request(['tab' => 'billing'], [], ['REQUEST_METHOD' => 'GET'], []), ['id' => (string) $this->clientId])
            ->body();

        // Each invoice is clickable through to its own page.
        $this->assertStringContainsString('/admin/invoices/' . $paid, $body);
        $this->assertStringContainsString('/admin/invoices/' . $cancelled, $body);

        // And each invoice's own row carries its own status colour. The two-way
        // paid/unpaid badge this replaced painted a cancelled invoice with the
        // "unpaid" red, so a voided invoice read as money still owed.
        //
        // Matched per row rather than by searching the page for the badge class:
        // the same tab's services, domains and recurring-invoice tables use the
        // shared badge classes too, so a page-wide assertion would pass or fail
        // for reasons unrelated to the invoices.
        $this->assertMatchesRegularExpression('~href="/admin/invoices/' . $paid . '".*?admin-detail-badge--paid~s', $body);
        $this->assertMatchesRegularExpression('~href="/admin/invoices/' . $cancelled . '".*?admin-detail-badge--cancelled~s', $body);
    }

    // --- wiring ----------------------------------------------------------

    public function test_every_tab_still_renders(): void
    {
        $this->ticketFor($this->clientId, 'A ticket');

        foreach (['summary', 'profile', 'contacts', 'billing', 'log', 'message'] as $tab) {
            $response = $this->controller->show(
                new Request(['tab' => $tab], [], ['REQUEST_METHOD' => 'GET'], []),
                ['id' => (string) $this->clientId]
            );

            $this->assertSame(200, $response->status(), "the {$tab} tab should still render");
        }
    }

    /**
     * The routes and the controller methods are two halves of one seam that
     * nothing else type-checks: the router only discovers a mistyped method
     * name when someone actually hits the URL. Comparing them here catches it
     * in the suite instead.
     */
    public function test_the_message_tab_routes_point_at_real_controller_methods(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/clients.php');

        $expected = [
            '/admin/clients/{id}/tickets/{ticketId}/reply' => 'replyToTicket',
            '/admin/clients/{id}/tickets/{ticketId}/ai-draft' => 'aiDraftTicketReply',
            '/admin/clients/{id}/tickets/{ticketId}/ai-refine' => 'aiRefineTicketReply',
        ];

        foreach ($expected as $path => $method) {
            $this->assertStringContainsString("'{$path}'", $routes, "route {$path} is not registered");
            $this->assertTrue(method_exists(ClientController::class, $method), "{$method}() does not exist on the controller");
        }
    }

    // --- helpers ---------------------------------------------------------

    private function invoice(float $total, string $status): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, discount_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, 1.0000, ?, ?, ?)',
            [$this->clientId, $status, $total, 0.0, 0.0, $total, substr($now, 0, 10), $now, $now]
        );
    }

    private function showMessageTab(?int $ticketId = null): string
    {
        $query = ['tab' => 'message'];
        if ($ticketId !== null) {
            $query['ticket_id'] = (string) $ticketId;
        }

        return $this->controller
            ->show(new Request($query, [], ['REQUEST_METHOD' => 'GET'], []), ['id' => (string) $this->clientId])
            ->body();
    }

    /** @param array<string, string> $fields */
    private function post(array $fields): Request
    {
        return new Request([], $fields, ['REQUEST_METHOD' => 'POST'], []);
    }

    private function ticketFor(int $clientId, string $subject): int
    {
        return $this->tickets->create([
            'client_id' => $clientId,
            'email' => 'ticket-' . $clientId . '@example.test',
            'department_id' => $this->departmentId,
            'subject' => $subject,
            'status' => 'open',
        ]);
    }
}
