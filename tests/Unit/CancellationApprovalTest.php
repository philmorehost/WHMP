<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\CancellationRequestRepository;
use CodeVault\Billing\CancellationRequestService;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Mail\Mailer;
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

            public function send(string $to, string $subject, string $html): void
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
            }
        });

        $this->service = new CancellationRequestService(
            $this->cancellations,
            $this->services,
            $container->make(\CodeVault\Mail\EmailDispatcher::class),
            $this->db
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

    private function createService(string $status): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $this->productId, 'Hosting Plan', 'monthly', 10.00, $status, '2026-12-31', $now, $now]
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
}
