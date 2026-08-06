<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Billing\CancellationRequestRepository;
use CodeVault\Billing\ClientServiceController;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Provisioning\InterServerVpsProvisioningModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;
use DateTimeImmutable;

/**
 * The client-facing VPS management tools. Anything the vendor API cannot
 * perform — an unprovisioned console, an unresolvable VPS, a snapshot the
 * hypervisor refuses — must open a support ticket rather than report a
 * fake success or drop the request with an error. These tests pin that the
 * power / vnc / backup / rdns actions hit the real module and, on failure,
 * land in the tickets table.
 */
final class ClientServiceControllerTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private ServerRepository $servers;
    private ServerGroupRepository $serverGroups;
    private ProductRepository $products;
    private ClientRepository $clients;
    private DepartmentRepository $departments;
    private FakeHttpClient $http;
    private ProvisioningService $provisioning;
    private TicketService $tickets;
    private ClientServiceController $controller;
    private int $clientId;
    private int $serverId;
    private int $productId;
    private int $departmentId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->services = new ServiceRepository($this->db);
        $this->servers = new ServerRepository($this->db);
        $this->serverGroups = new ServerGroupRepository($this->db);
        $this->products = new ProductRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->departments = new DepartmentRepository($this->db);

        $this->clientId = $this->clients->create([
            'email' => 'vpsclient@example.test',
            'password' => 'secret123',
            'first_name' => 'Vps',
            'last_name' => 'Client',
        ]);

        $groupId = (new ProductGroupRepository($this->db))->create('VPS', null);
        $sgId = $this->serverGroups->create('VPS Group');
        $this->serverId = $this->servers->create([
            'server_group_id' => $sgId,
            'name' => 'InterServer',
            'hostname' => 'https://my.interserver.net/',
            'module_slug' => 'interserver-vps',
            'api_username' => 'hostbill',
            'api_token' => 'ISK123',
            'active' => 1,
        ]);

        $this->productId = $this->products->create([
            'product_group_id' => $groupId,
            'server_group_id' => $sgId,
            'name' => 'VPS Plan A',
        ]);

        $this->departmentId = $this->departments->create('VPS Support', null);

        $this->http = new FakeHttpClient();
        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $modules->register(
            ProvisioningModule::class,
            'interserver-vps',
            new InterServerVpsProvisioningModule($this->http)
        );

        $this->provisioning = new ProvisioningService($this->services, $this->products, $this->servers, $modules, $hooks);
        $this->tickets = new TicketService(
            new TicketRepository($this->db),
            new TicketReplyRepository($this->db),
            $hooks,
            new TicketAttachmentRepository($this->db)
        );

        $this->controller = $this->controllerForClient($this->clientId);
    }

    /** A WHMCS-style VPS: real hostname recorded, generic username. */
    private function createProvisionedVpsService(string $hostname = 'vps200.example.com'): int
    {
        $serviceId = $this->services->create([
            'client_id' => $this->clientId,
            'product_id' => $this->productId,
            'product_name' => 'VPS Plan A',
            'billing_cycle' => 'monthly',
            'amount' => 10.00,
            'status' => 'active',
            'hostname' => $hostname,
            'next_due_date' => (new DateTimeImmutable('+1 month'))->format('Y-m-d'),
        ]);

        $this->services->assignServer($serviceId, $this->serverId, 'root');

        return $serviceId;
    }

    private function request(array $body = [], string $uri = '/'): Request
    {
        return new Request([], $body, [
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '1.2.3.4',
            'REQUEST_URI' => $uri,
        ], []);
    }

    /** @return array<int, array<string, mixed>> */
    private function tickets(): array
    {
        return $this->db->select('SELECT * FROM tickets ORDER BY id');
    }

    /** @return array<int, array<string, mixed>> */
    private function repliesFor(int $ticketId): array
    {
        return $this->db->select('SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY id', [$ticketId]);
    }

    public function test_power_hits_the_real_module_and_redirects_with_success(): void
    {
        $this->http->respondWith(200, json_encode([
            ['vps_id' => '12', 'vps_hostname' => 'vps200.example.com'],
        ]));

        $serviceId = $this->createProvisionedVpsService();

        $response = $this->controller->power($this->request(['action' => 'restart']), ['id' => $serviceId]);

        $this->assertSame(302, $response->status());
        $location = $response->headers()['Location'];
        $this->assertStringContainsString("/client/services/{$serviceId}?msg=", $location);
        $this->assertStringNotContainsString('err=', $location);
        $this->assertSame('https://my.interserver.net/apiv2/vps/12/restart', $this->http->lastRequest()['url']);
        $this->assertSame([], $this->tickets(), 'a successful power action must not open a ticket');
    }

    public function test_power_opens_a_support_ticket_when_the_vps_cannot_be_resolved(): void
    {
        $this->http->respondWith(200, json_encode([
            ['vps_id' => '1', 'vps_hostname' => 'someone-elses-vps'],
        ]));

        $serviceId = $this->createProvisionedVpsService();

        $response = $this->controller->power($this->request(['action' => 'restart']), ['id' => $serviceId]);

        $this->assertSame(302, $response->status());
        $location = $response->headers()['Location'];
        $this->assertStringContainsString("?msg=", $location);
        $this->assertStringNotContainsString('err=', $location);

        $tickets = $this->tickets();
        $this->assertCount(1, $tickets, 'a power request the API cannot honour must become a support ticket');
        $this->assertSame($this->clientId, (int) $tickets[0]['client_id']);
        // The controller opens the ticket against the first department by
        // name — the seeded "General Support" here, not the VPS Support row.
        $this->assertSame((int) $this->departments->all()[0]['id'], (int) $tickets[0]['department_id']);
        $this->assertStringContainsString('restart', (string) $tickets[0]['subject']);
        $this->assertStringContainsString('#', (string) $tickets[0]['subject']);

        $replies = $this->repliesFor((int) $tickets[0]['id']);
        $this->assertCount(1, $replies);
        $this->assertStringContainsString('Could not find this VPS', (string) $replies[0]['message']);
    }

    public function test_backup_opens_a_support_ticket_when_the_api_rejects_the_snapshot(): void
    {
        $this->http->respondInSequence([
            ['status' => 200, 'body' => json_encode(['0' => ['vps_id' => '12', 'vps_hostname' => 'vps200.example.com']])],
            ['status' => 400, 'body' => json_encode(['success' => false, 'text' => 'Backups are disabled for this type'])],
        ]);

        $serviceId = $this->createProvisionedVpsService();

        $response = $this->controller->backup($this->request(), ['id' => $serviceId]);

        $this->assertSame(302, $response->status());
        $this->assertStringNotContainsString('err=', $response->headers()['Location']);

        $tickets = $this->tickets();
        $this->assertCount(1, $tickets);
        $this->assertStringContainsString('on-demand backup', (string) $tickets[0]['subject']);
        $this->assertStringContainsString('Backups are disabled', (string) $this->repliesFor((int) $tickets[0]['id'])[0]['message']);
    }

    public function test_vnc_opens_a_support_ticket_when_no_console_is_provisioned(): void
    {
        $this->http->respondInSequence([
            ['status' => 200, 'body' => json_encode(['0' => ['vps_id' => '12', 'vps_hostname' => 'vps200.example.com']])],
            ['status' => 200, 'body' => json_encode(['vps_id' => 12])],
        ]);

        $serviceId = $this->createProvisionedVpsService();

        $response = $this->controller->vnc($this->request(), ['id' => $serviceId]);

        $this->assertSame(302, $response->status());
        $this->assertStringNotContainsString('err=', $response->headers()['Location']);

        $tickets = $this->tickets();
        $this->assertCount(1, $tickets);
        $this->assertStringContainsString('VNC console', (string) $tickets[0]['subject']);
        $this->assertStringContainsString('No VNC console is provisioned', (string) $this->repliesFor((int) $tickets[0]['id'])[0]['message']);
    }

    public function test_rdns_opens_a_support_ticket_when_the_api_rejects_the_update(): void
    {
        $this->http->respondInSequence([
            ['status' => 200, 'body' => json_encode(['0' => ['vps_id' => '12', 'vps_hostname' => 'vps200.example.com']])],
            ['status' => 400, 'body' => json_encode(['success' => false, 'text' => 'Invalid IP address'])],
        ]);

        $serviceId = $this->createProvisionedVpsService();

        $response = $this->controller->rdns(
            $this->request(['ip' => '10.0.0.7', 'rdns' => 'ptr.example.com']),
            ['id' => $serviceId]
        );

        $this->assertSame(302, $response->status());
        $this->assertStringNotContainsString('err=', $response->headers()['Location']);

        $tickets = $this->tickets();
        $this->assertCount(1, $tickets);
        $this->assertStringContainsString('reverse DNS', (string) $tickets[0]['subject']);
        $this->assertStringContainsString('PTR for 10.0.0.7', (string) $this->repliesFor((int) $tickets[0]['id'])[0]['message']);
    }

    public function test_action_on_another_clients_service_is_denied(): void
    {
        $serviceId = $this->createProvisionedVpsService();

        $otherClientId = $this->clients->create([
            'email' => 'other@example.test',
            'password' => 'secret123',
            'first_name' => 'Other',
            'last_name' => 'Client',
        ]);
        $otherController = $this->controllerForClient($otherClientId);

        $response = $otherController->power($this->request(['action' => 'restart']), ['id' => $serviceId]);

        $this->assertSame(404, $response->status());
        $this->assertSame([], $this->tickets());
        $this->assertSame([], $this->http->requests, 'no API call for a service the client does not own');
    }

    private function controllerForClient(int $clientId): ClientServiceController
    {
        $session = $this->createMock(SessionManager::class);
        $session->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null) => $key === 'client_id' ? $clientId : $default
        );

        return new ClientServiceController(
            new ClientAuthGuard($session, $this->clients),
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->services,
            $this->provisioning,
            $this->servers,
            new CurrencyService(new CurrencyRepository($this->db)),
            new CancellationRequestRepository($this->db),
            new InvoiceRepository($this->db),
            new ActivityLogger($this->db),
            $this->tickets,
            $this->departments,
            new AddonModuleRepository($this->db),
            $this->db
        );
    }
}
