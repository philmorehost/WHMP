<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\ClientTicketController;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketAttachmentService;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;
use DateTimeImmutable;

/**
 * "What is this about?" on the support ticket form.
 *
 * A client picks one of their own services/domains when raising a ticket, so
 * the ticket (and the admin page) can name the exact item instead of staff
 * guessing it from the message body — and the admin can click through to
 * /admin/services/{id} or /admin/domains/{id} to review it.
 *
 * The ids arrive from a form field, so the security half of this file matters
 * as much as the feature half: a client must not be able to attach *someone
 * else's* service or domain to a ticket, which would both mislead staff and
 * put another account's item on their own ticket page.
 */
final class TicketRelatedItemTest extends DatabaseTestCase
{
    private TicketRepository $tickets;
    private TicketService $ticketService;
    private ClientTicketController $controller;
    private ClientRepository $clients;
    private int $clientId;
    private int $otherClientId;
    private int $departmentId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->tickets = new TicketRepository($this->db);
        $replies = new TicketReplyRepository($this->db);
        $departments = new DepartmentRepository($this->db);
        $attachments = new TicketAttachmentRepository($this->db);
        $this->ticketService = new TicketService($this->tickets, $replies, new HookDispatcher(), $attachments);

        $this->departmentId = $departments->create('General Support', 'support@example.test');

        $this->clientId = $this->clients->create([
            'email' => 'ticketowner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Ticket',
            'last_name' => 'Owner',
        ]);

        $this->otherClientId = $this->clients->create([
            'email' => 'otherclient@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Other',
            'last_name' => 'Client',
        ]);

        $configDir = sys_get_temp_dir() . '/codevault-ticket-item-test-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $guard = new ClientAuthGuard($session, $this->clients);
        // Seed the guard's own session key rather than calling login(), which
        // session_regenerate_id()s and warns in CLI (no active session).
        $_SESSION['client_id'] = $this->clientId;

        $container = new Container();
        $container->instance(SessionManager::class, $session);
        $container->instance(Database::class, $this->db);
        $container->instance(SettingsRepository::class, new SettingsRepository($this->db));
        App::setContainer($container);

        $attachmentDir = sys_get_temp_dir() . '/codevault-ticket-item-files-' . uniqid();
        mkdir($attachmentDir);

        $this->controller = new ClientTicketController(
            $guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->tickets,
            $replies,
            $departments,
            $this->ticketService,
            $attachments,
            new TicketAttachmentService($attachments, $attachmentDir),
            new ServiceRepository($this->db),
            new DomainRepository($this->db)
        );
    }

    // --- the feature ------------------------------------------------------

    public function test_opening_a_ticket_records_the_service_and_domain_it_is_about(): void
    {
        $serviceId = $this->serviceFor($this->clientId, 'cPanel Hosting');
        $domainId = $this->domainFor($this->clientId, 'example.test');

        $ticketId = $this->ticketService->open(
            $this->clientId,
            'ticketowner@example.test',
            $this->departmentId,
            'Site is down',
            'Ticket Owner',
            'My hosting has stopped responding.',
            $serviceId,
            $domainId
        );

        $ticket = $this->tickets->find($ticketId);
        $this->assertSame($serviceId, (int) $ticket['service_id']);
        $this->assertSame($domainId, (int) $ticket['domain_id']);

        // find() joins the labels the client and admin pages print.
        $this->assertSame('cPanel Hosting', $ticket['service_product_name']);
        $this->assertSame('example.test', $ticket['related_domain_name']);
    }

    public function test_a_ticket_opened_without_an_item_leaves_both_columns_null(): void
    {
        $ticketId = $this->ticketService->open(
            $this->clientId,
            'ticketowner@example.test',
            $this->departmentId,
            'Billing question',
            'Ticket Owner',
            'Why was I charged twice?'
        );

        $ticket = $this->tickets->find($ticketId);
        $this->assertNull($ticket['service_id']);
        $this->assertNull($ticket['domain_id']);
    }

    public function test_the_form_lists_the_clients_own_services_and_domains(): void
    {
        $ownServiceId = $this->serviceFor($this->clientId, 'My Hosting Plan');
        $ownDomainId = $this->domainFor($this->clientId, 'mine.test');
        $foreignServiceId = $this->serviceFor($this->otherClientId, 'Someone Elses Plan');
        $foreignDomainId = $this->domainFor($this->otherClientId, 'notmine.test');

        $body = $this->controller->create(new Request([], [], ['REQUEST_METHOD' => 'GET'], []))->body();

        // Scoped to the pickers themselves: the surrounding layout lists
        // product groups and nav links, so a bare substring assertion on the
        // whole page would pass or fail for reasons that have nothing to do
        // with this form.
        $serviceSelect = $this->selectBlock($body, 'service_id');
        $this->assertStringContainsString('My Hosting Plan', $serviceSelect);
        $this->assertStringContainsString('value="' . $ownServiceId . '"', $serviceSelect);
        $this->assertStringNotContainsString('Someone Elses Plan', $serviceSelect);
        $this->assertStringNotContainsString('value="' . $foreignServiceId . '"', $serviceSelect);

        $domainSelect = $this->selectBlock($body, 'domain_id');
        $this->assertStringContainsString('mine.test', $domainSelect);
        $this->assertStringContainsString('value="' . $ownDomainId . '"', $domainSelect);
        $this->assertStringNotContainsString('notmine.test', $domainSelect);
        $this->assertStringNotContainsString('value="' . $foreignDomainId . '"', $domainSelect);
    }

    public function test_the_form_preselects_an_item_handed_over_in_the_query_string(): void
    {
        $serviceId = $this->serviceFor($this->clientId, 'Preselected Plan');

        $body = $this->controller
            ->create(new Request(['service_id' => (string) $serviceId], [], ['REQUEST_METHOD' => 'GET'], []))
            ->body();

        // The rendered option for that service carries the selected attribute.
        // A "raise a ticket about this service" link relies on this. The id is
        // re-authorised by ownedServiceId() on the way in; the store() tests
        // below are what pin that rule, because a foreign id could not be
        // selected on this page in the first place — the picker only renders
        // the client's own services.
        $this->assertMatchesRegularExpression(
            '/<option value="' . $serviceId . '" selected>/',
            $this->selectBlock($body, 'service_id')
        );
    }

    // --- the gate --------------------------------------------------------

    public function test_a_client_can_attach_their_own_service_and_domain(): void
    {
        $serviceId = $this->serviceFor($this->clientId, 'Own Hosting');
        $domainId = $this->domainFor($this->clientId, 'own.test');

        $this->controller->store($this->storeRequest([
            'department_id' => (string) $this->departmentId,
            'subject' => 'About my hosting',
            'message' => 'Please look into this.',
            'service_id' => (string) $serviceId,
            'domain_id' => (string) $domainId,
        ]));

        $ticket = $this->onlyTicketFor($this->clientId);
        $this->assertSame($serviceId, (int) $ticket['service_id']);
        $this->assertSame($domainId, (int) $ticket['domain_id']);
    }

    public function test_a_client_cannot_attach_another_clients_service_or_domain(): void
    {
        $foreignServiceId = $this->serviceFor($this->otherClientId, 'Someone Elses Plan');
        $foreignDomainId = $this->domainFor($this->otherClientId, 'notmine.test');

        $this->controller->store($this->storeRequest([
            'department_id' => (string) $this->departmentId,
            'subject' => 'Trying it on',
            'message' => 'This should not attach their item.',
            'service_id' => (string) $foreignServiceId,
            'domain_id' => (string) $foreignDomainId,
        ]));

        // The ticket is still opened (a tampered id must not break support),
        // but the other account's items are not attached to it.
        $ticket = $this->onlyTicketFor($this->clientId);
        $this->assertNull($ticket['service_id'], 'a service belonging to another client must not be recorded');
        $this->assertNull($ticket['domain_id'], 'a domain belonging to another client must not be recorded');
    }

    public function test_a_stale_service_id_degrades_to_no_item_instead_of_failing(): void
    {
        $this->controller->store($this->storeRequest([
            'department_id' => (string) $this->departmentId,
            'subject' => 'Deleted service',
            'message' => 'The service was removed while my form was open.',
            'service_id' => '999999',
            'domain_id' => '0',
        ]));

        $ticket = $this->onlyTicketFor($this->clientId);
        $this->assertNull($ticket['service_id']);
        $this->assertNull($ticket['domain_id']);
    }

    // --- helpers ---------------------------------------------------------

    /**
     * The inner HTML of one `<select name="...">` on the rendered page.
     *
     * Assertions about the pickers have to be scoped to the picker: the client
     * layout renders the storefront's product-group menu, which contains
     * fixture product names, so asserting on the whole page proves nothing.
     */
    private function selectBlock(string $body, string $name): string
    {
        $namePosition = strpos($body, 'name="' . $name . '"');
        $this->assertNotFalse($namePosition, "the form should render a {$name} select");

        $openEnd = strpos($body, '>', (int) $namePosition);
        $closeStart = strpos($body, '</select>', (int) $openEnd);

        $this->assertNotFalse($openEnd, "the {$name} select should have an opening tag");
        $this->assertNotFalse($closeStart, "the {$name} select should be closed");

        return substr($body, (int) $openEnd, (int) $closeStart - (int) $openEnd);
    }

    /** @param array<string, string> $fields */
    private function storeRequest(array $fields): Request
    {
        return new Request([], $fields, ['REQUEST_METHOD' => 'POST'], []);
    }

    /** @return array<string, mixed> */
    private function onlyTicketFor(int $clientId): array
    {
        $tickets = $this->tickets->forClient($clientId);
        $this->assertCount(1, $tickets, 'exactly one ticket should have been opened');

        return $this->tickets->find((int) $tickets[0]['id']);
    }

    private function serviceFor(int $clientId, string $productName): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $groupId = (int) $this->db->insert(
            'INSERT INTO product_groups (name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['Group ' . $productName, 0, $now, $now]
        );
        $productId = (int) $this->db->insert(
            'INSERT INTO products (product_group_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$groupId, $productName, $now, $now]
        );

        return (int) $this->db->insert(
            "INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, domain, next_due_date, created_at, updated_at) VALUES (?, ?, ?, 'monthly', ?, 'active', ?, ?, ?, ?)",
            [$clientId, $productId, $productName, 9.99, strtolower(str_replace(' ', '-', $productName)) . '.test', $now, $now, $now]
        );
    }

    private function domainFor(int $clientId, string $domainName): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO domains (client_id, domain_name, tld, registrar_slug, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $domainName, 'test', 'local', 'active', $now, $now]
        );
    }
}
