<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\CancellationRequestRepository;
use CodeVault\Billing\CancellationRequestService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Mail\Mailer;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Provisioning\LinksRemoteServices;
use CodeVault\Provisioning\LocalProvisioningModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * The approve flow must never error and must intelligently complete:
 *  - approving when the service is ALREADY cancelled → request completed.
 *  - approving an immediate cancellation → service cancelled + request completed.
 *  - approving a due-date cancellation → stays approved (scheduled), service
 *    untouched until the cancellation-processor cron.
 */
final class CancellationApprovalTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private CancellationRequestRepository $cancellations;
    private CancellationRequestService $service;
    private int $clientId;
    private int $adminId;
    private int $productId;
    private FakeRemoteCancellationModule $remote;
    /** @var array<int, array{to: string, subject: string, html: string}> */
    private array $sentMails = [];

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        $container = \CodeVault\Support\App::container();
        $container->instance(Database::class, $this->db);
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->services = new ServiceRepository($this->db);
        $this->cancellations = new CancellationRequestRepository($this->db);

        $this->sentMails = [];
        $container->instance(Mailer::class, new class ($this->sentMails) implements Mailer {
            /** @param array<int, array{to: string, subject: string, html: string}> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function send(string $to, string $subject, string $html, ?array $from = null): void
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
            }
        });

        $servers = new ServerRepository($this->db);
        $localModule = new LocalProvisioningModule(sys_get_temp_dir() . '/codevault-cancel-appr-' . uniqid());
        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $modules->register(ProvisioningModule::class, 'local', $localModule);
        $this->remote = new FakeRemoteCancellationModule();
        $modules->register(ProvisioningModule::class, 'interserver-vps', $this->remote);
        $modules->register(ProvisioningModule::class, 'interserver-dedicated', $this->remote);
        $provisioning = new ProvisioningService($this->services, new ProductRepository($this->db), $servers, $modules, $hooks);

        $this->service = new CancellationRequestService(
            $this->cancellations,
            $this->services,
            $container->make(\CodeVault\Mail\EmailDispatcher::class),
            $this->db,
            new InvoiceRepository($this->db),
            $servers,
            $provisioning
        );

        $this->clientId = (new ClientRepository($this->db))->create([
            'email' => 'cancelappr@example.test',
            'password' => 'secret123',
            'first_name' => 'Cancel',
            'last_name' => 'Approver',
        ]);
        $this->adminId = (new AdminRepository($this->db))->create('ops', 'ops@example.test', 'secret123', 'Ops Admin', null);

        $groupId = (new ProductGroupRepository($this->db))->create('Test Group', null);
        $this->productId = (new ProductRepository($this->db))->create([
            'product_group_id' => $groupId,
            'name' => 'Hosting Plan',
        ]);
    }

    private function createService(string $status, ?string $nextDueDate = '2026-12-31', ?int $serverId = null): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, server_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $this->productId, 'Hosting Plan', 'monthly', 10.00, $status, $nextDueDate, $serverId, $now, $now]
        );
    }

    private function createServer(string $slug): int
    {
        return (new ServerRepository($this->db))->create([
            'name' => 'Remote ' . $slug,
            'hostname' => 'remote.example.test',
            'module_slug' => $slug,
            'api_token' => 'KEY',
        ]);
    }

    private function createUnpaidInvoice(int $serviceId, float $amount = 10.0): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, service_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $serviceId, 'unpaid', $amount, 0.0, $amount, '2026-12-31', $now, $now]
        );
    }

    public function test_approve_marks_completed_when_the_service_is_already_cancelled(): void
    {
        $serviceId = $this->createService('cancelled');
        $requestId = $this->cancellations->createRequest($serviceId, 'immediate', 'Not needed anymore');

        $result = $this->service->approveCancellation($requestId, $this->adminId, 'Ok');

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['status']);
        $this->assertStringContainsString('already cancelled', $result['message']);

        $request = $this->cancellations->findById($requestId);
        $this->assertSame('completed', $request['status']);
    }

    public function test_approve_immediate_cancellation_cancels_the_service_and_completes(): void
    {
        $serviceId = $this->createService('active');
        $requestId = $this->cancellations->createRequest($serviceId, 'immediate', 'Leaving');

        $result = $this->service->approveCancellation($requestId, $this->adminId);

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('cancelled', $this->services->find($serviceId)['status']);
        $this->assertSame('completed', $this->cancellations->findById($requestId)['status']);
    }

    public function test_approve_due_date_cancellation_stays_approved_until_the_date(): void
    {
        $serviceId = $this->createService('active');
        $future = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
        $requestId = $this->cancellations->createRequest($serviceId, 'due_date', 'End of contract', null, $future);

        $result = $this->service->approveCancellation($requestId, $this->adminId);

        $this->assertTrue($result['success']);
        $this->assertSame('approved', $result['status']);
        $this->assertSame('active', $this->services->find($serviceId)['status']);
        $this->assertSame('approved', $this->cancellations->findById($requestId)['status']);
    }

    public function test_approve_cancels_unpaid_invoices_for_the_service(): void
    {
        $serviceId = $this->createService('active');
        $invoiceId = $this->createUnpaidInvoice($serviceId);
        $requestId = $this->cancellations->createRequest($serviceId, 'immediate', 'Leaving');

        $this->service->approveCancellation($requestId, $this->adminId);

        $invoice = (new \CodeVault\Billing\InvoiceRepository($this->db))->find($invoiceId);
        $this->assertSame('cancelled', $invoice['status']);
    }

    public function test_client_immediate_cancel_marks_service_and_cancels_invoices(): void
    {
        $serviceId = $this->createService('active');
        $invoiceId = $this->createUnpaidInvoice($serviceId);

        $this->service->clientRequestsCancellation($serviceId, $this->clientId, 'immediate', 'Leaving now');

        $this->assertSame('cancelled', $this->services->find($serviceId)['status']);
        $invoice = (new \CodeVault\Billing\InvoiceRepository($this->db))->find($invoiceId);
        $this->assertSame('cancelled', $invoice['status']);
    }

    public function test_client_end_of_period_cancel_records_due_date_request_and_cancels_renewal(): void
    {
        $nextDue = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
        $serviceId = $this->createService('active', $nextDue);
        $invoiceId = $this->createUnpaidInvoice($serviceId);

        $requestId = $this->service->clientRequestsCancellation($serviceId, $this->clientId, 'end_of_period', 'Moving on');

        $this->assertGreaterThan(0, $requestId);
        $request = $this->cancellations->findById($requestId);
        $this->assertSame('due_date', $request['cancellation_type']);
        $this->assertSame('end_of_period', $request['type']);
        $this->assertSame($nextDue, $request['cancel_date']);
        $this->assertSame($this->clientId, (int) $request['client_id']);

        // The renewal invoice is cancelled, the service stays active.
        $invoice = (new \CodeVault\Billing\InvoiceRepository($this->db))->find($invoiceId);
        $this->assertSame('cancelled', $invoice['status']);
        $this->assertSame('active', $this->services->find($serviceId)['status']);
    }

    public function test_switching_a_pending_request_to_immediate_cancels_now_and_completes(): void
    {
        $nextDue = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
        $serviceId = $this->createService('active', $nextDue);
        $invoiceId = $this->createUnpaidInvoice($serviceId);
        $requestId = $this->cancellations->createRequest($serviceId, 'end_of_period', 'Moving on', $this->clientId, $nextDue);

        $result = $this->service->switchToImmediate($requestId, $this->adminId, 'Client asked to end it now');

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('cancelled', $this->services->find($serviceId)['status'], 'the service ends NOW, not at the scheduled date');

        $request = $this->cancellations->findById($requestId);
        $this->assertSame('completed', $request['status']);
        $this->assertSame('immediate', $request['cancellation_type'], 'the stored option is flipped to immediate');
        $this->assertSame($this->adminId, (int) $request['reviewed_by'], 'the switching admin is recorded as the reviewer');
        $this->assertSame('Client asked to end it now', $request['admin_notes']);

        $invoice = (new InvoiceRepository($this->db))->find($invoiceId);
        $this->assertSame('cancelled', $invoice['status'], 'billing stops on the switched cancellation');
    }

    public function test_switching_an_approved_request_to_immediate_cancels_before_its_date(): void
    {
        $future = (new DateTimeImmutable('+45 days'))->format('Y-m-d');
        $serviceId = $this->createService('active', $future);
        $requestId = $this->cancellations->createRequest($serviceId, 'end_of_period', 'End of contract', $this->clientId, $future);
        $this->cancellations->approve($requestId, $this->adminId, 'Ok');

        $result = $this->service->switchToImmediate($requestId, $this->adminId);

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('cancelled', $this->services->find($serviceId)['status'], 'approved but not yet due — the switch cancels it now');
        $this->assertSame('completed', $this->cancellations->findById($requestId)['status']);
    }

    public function test_a_completed_request_cannot_be_switched(): void
    {
        $serviceId = $this->createService('cancelled');
        $requestId = $this->cancellations->createRequest($serviceId, 'end_of_period', 'Done');
        $this->cancellations->approve($requestId, $this->adminId);
        $this->cancellations->markCompleted($requestId);

        $result = $this->service->switchToImmediate($requestId, $this->adminId);

        $this->assertFalse($result['success']);
        $this->assertSame('completed', $result['status']);
        $this->assertStringContainsString('already been carried out or withdrawn', $result['message']);
    }

    public function test_switching_marks_completed_when_the_service_is_already_cancelled(): void
    {
        $nextDue = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
        $serviceId = $this->createService('cancelled', $nextDue);
        $requestId = $this->cancellations->createRequest($serviceId, 'end_of_period', 'Pending', $this->clientId, $nextDue);

        $result = $this->service->switchToImmediate($requestId, $this->adminId);

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['status']);
        $this->assertStringContainsString('already cancelled', $result['message']);
        $this->assertSame('completed', $this->cancellations->findById($requestId)['status']);
    }

    public function test_switching_an_immediate_request_is_refused_as_a_no_op(): void
    {
        $serviceId = $this->createService('active');
        $requestId = $this->cancellations->createRequest($serviceId, 'immediate', 'Leaving');

        $result = $this->service->switchToImmediate($requestId, $this->adminId);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already set to cancel immediately', $result['message']);
        $this->assertSame('active', $this->services->find($serviceId)['status'], 'a refused switch must not touch the service');
    }

    public function test_client_immediate_option_via_the_request_endpoint_cancels_without_a_pending_record(): void
    {
        $serviceId = $this->createService('active');
        $invoiceId = $this->createUnpaidInvoice($serviceId);

        $requestId = $this->service->clientRequestsCancellation($serviceId, $this->clientId, 'immediate', 'Ending now');

        $this->assertSame(0, $requestId, 'an immediate client cancel completes instantly — no pending request to approve');
        $this->assertSame('cancelled', $this->services->find($serviceId)['status']);
        $this->assertSame('cancelled', (new InvoiceRepository($this->db))->find($invoiceId)['status']);
        $this->assertNull($this->cancellations->findPendingForService($serviceId));
    }

    public function test_approving_a_vps_cancellation_cancels_it_through_the_provider_api(): void
    {
        $serverId = $this->createServer('interserver-vps');
        $serviceId = $this->createService('active', '2026-12-31', $serverId);
        $requestId = $this->cancellations->createRequest($serviceId, 'immediate', 'Leaving');

        $result = $this->service->approveCancellation($requestId, $this->adminId);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->remote->terminateCalls);
        $this->assertSame('KEY', $this->remote->terminateCalls[0]['server']['api_token']);
        $this->assertSame('terminated', $this->services->find($serviceId)['status']);
        $this->assertSame('completed', $this->cancellations->findById($requestId)['status']);
    }

    public function test_scheduled_vps_cancellation_is_sent_to_the_api_when_its_date_arrives(): void
    {
        $serverId = $this->createServer('interserver-vps');
        $serviceId = $this->createService('active', '2026-12-31', $serverId);
        $requestId = $this->cancellations->createRequest($serviceId, 'end_of_period', 'End of contract', $this->clientId, '2026-12-31');

        $this->service->approveCancellation($requestId, $this->adminId);
        $this->assertCount(0, $this->remote->terminateCalls);
        $this->assertSame('active', $this->services->find($serviceId)['status']);

        $this->db->update('UPDATE cancellation_requests SET cancel_date = ? WHERE id = ?', ['2026-01-01', $requestId]);
        $this->service->processDueCancellations();

        $this->assertCount(1, $this->remote->terminateCalls);
        $this->assertSame('terminated', $this->services->find($serviceId)['status']);
        $this->assertSame('completed', $this->cancellations->findById($requestId)['status']);
    }

    public function test_a_failed_provider_cancellation_completes_but_alerts_the_admins(): void
    {
        $this->remote->terminateSucceeds = false;
        $serverId = $this->createServer('interserver-vps');
        $serviceId = $this->createService('active', '2026-12-31', $serverId);
        $requestId = $this->cancellations->createRequest($serviceId, 'immediate', 'Leaving');

        $result = $this->service->approveCancellation($requestId, $this->adminId);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('could not be cancelled automatically', $result['message']);
        $this->assertSame('active', $this->services->find($serviceId)['status']);
        $this->assertSame('completed', $this->cancellations->findById($requestId)['status']);
        $failures = array_values(array_filter(
            $this->sentMails,
            fn (array $m): bool => $m['subject'] === 'Service Termination Failed'
        ));
        $this->assertNotEmpty($failures);
    }

    public function test_switching_a_dedicated_server_cancellation_to_immediate_goes_through_the_api(): void
    {
        $serverId = $this->createServer('interserver-dedicated');
        $serviceId = $this->createService('active', '2026-12-31', $serverId);
        $requestId = $this->cancellations->createRequest($serviceId, 'end_of_period', 'Moving on', $this->clientId, '2026-12-31');

        $result = $this->service->switchToImmediate($requestId, $this->adminId, 'Client asked to end it now');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->remote->terminateCalls);
        $this->assertSame('terminated', $this->services->find($serviceId)['status']);
        $this->assertSame('completed', $this->cancellations->findById($requestId)['status']);
    }

    public function test_a_nocix_dedicated_cancellation_is_marked_cancelled_locally_without_an_api_call(): void
    {
        $serverId = $this->createServer('nocix-dedicated');
        $serviceId = $this->createService('active', '2026-12-31', $serverId);
        $requestId = $this->cancellations->createRequest($serviceId, 'immediate', 'Leaving');

        $result = $this->service->approveCancellation($requestId, $this->adminId);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $this->remote->terminateCalls);
        $this->assertSame('cancelled', $this->services->find($serviceId)['status']);
        $this->assertSame('completed', $this->cancellations->findById($requestId)['status']);
    }
}

/**
 * Stands in for the InterServer VPS / dedicated modules: records every
 * terminate() call (the provider cancellation API) and can be told to fail,
 * so the cancellation flow is tested without any real HTTP.
 */
final class FakeRemoteCancellationModule implements ProvisioningModule, LinksRemoteServices
{
    /** @var array<int, array<string, mixed>> */
    public array $terminateCalls = [];

    public bool $terminateSucceeds = true;

    public function create(array $params): array
    {
        return ['success' => true, 'message' => 'ok'];
    }

    public function suspend(array $params): array
    {
        return ['success' => true, 'message' => 'ok'];
    }

    public function unsuspend(array $params): array
    {
        return ['success' => true, 'message' => 'ok'];
    }

    public function terminate(array $params): array
    {
        $this->terminateCalls[] = $params;

        return $this->terminateSucceeds
            ? ['success' => true, 'message' => 'Cancelled with the provider.']
            : ['success' => false, 'message' => 'This VPS is not linked to a VPS on the provider account yet.'];
    }

    public function changePassword(array $params): array
    {
        return ['success' => true, 'message' => 'ok'];
    }

    public function changePackage(array $params): array
    {
        return ['success' => true, 'message' => 'ok'];
    }

    public function singleSignOn(array $params): array
    {
        return ['success' => true, 'message' => 'ok', 'url' => 'https://panel.example.test/sso'];
    }

    public function usage(array $params): array
    {
        return [];
    }

    public function testConnection(array $params): array
    {
        return ['success' => true, 'message' => 'ok'];
    }

    public function remoteServices(array $server): array
    {
        return ['success' => true, 'message' => 'ok', 'services' => []];
    }

    public function resolveRemote(array $params): array
    {
        return [
            'ref' => isset($params['remote_id']) ? (string) $params['remote_id'] : null,
            'via' => isset($params['remote_id']) ? 'linked' : 'none',
        ];
    }
}
