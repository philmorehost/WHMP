<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * The admin's one-click "cancel unpaid invoices for cancelled/terminated
 * services" cleanup (AdminInvoiceController::cancelForDeadServices →
 * InvoiceRepository::cancelUnpaidForDeadServices).
 *
 * The rule in one line: an invoice that is still UNPAID and belongs to a
 * service that no longer exists (cancelled or terminated) can never be
 * collected — cancel it. Everything else is off-limits: paid/refunded
 * invoices stay history, invoices with no service may be perfectly
 * collectable, and an active or suspended service's invoices are live debt.
 */
final class InvoiceDeadServiceCancellationTest extends DatabaseTestCase
{
    private InvoiceRepository $invoices;
    private ClientRepository $clients;
    private int $clientId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->invoices = new InvoiceRepository($this->db);
        $this->clients = new ClientRepository($this->db);

        $this->clientId = $this->clients->create([
            'email' => 'dead-service@example.test',
            'password' => 'secret123',
            'first_name' => 'Dead',
            'last_name' => 'Service',
        ]);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $groupId = (int) $this->db->insert(
            'INSERT INTO product_groups (name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['Group', 0, $now, $now]
        );
        $this->productId = (int) $this->db->insert(
            'INSERT INTO products (product_group_id, name, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$groupId, 'Seeded product', 'active', 0, $now, $now]
        );
    }

    /** @return int the service id */
    private function createService(string $status): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $this->productId, 'Seeded service', 'monthly', 9.99, $status, (new DateTimeImmutable('+1 month'))->format('Y-m-d'), $now, $now]
        );
    }

    /** @return int the invoice id */
    private function createInvoice(?int $serviceId, string $status = 'unpaid', float $total = 25.00): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $paidAt = $status === 'paid' ? $now : null;

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, service_id, status, subtotal, total, due_date, created_at, updated_at, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $serviceId, $status, $total, $total, (new DateTimeImmutable('-10 days'))->format('Y-m-d'), $now, $now, $paidAt]
        );
    }

    public function test_cancels_only_unpaid_invoices_of_dead_services(): void
    {
        $terminated = $this->createService('terminated');
        $cancelled = $this->createService('cancelled');
        $active = $this->createService('active');
        $suspended = $this->createService('suspended');

        $a = $this->createInvoice($terminated);            // target
        $b = $this->createInvoice($cancelled);             // target
        $c = $this->createInvoice($active);                // live debt — untouched
        $d = $this->createInvoice($suspended);             // live debt — untouched
        $e = $this->createInvoice(null);                   // no service — untouched
        $f = $this->createInvoice($terminated, 'paid');    // history — untouched
        $g = $this->createInvoice($terminated, 'refunded');// history — untouched
        $h = $this->createInvoice($terminated, 'cancelled');// already handled — untouched

        $this->assertSame(2, $this->invoices->countUnpaidForDeadServices(), 'preview counts exactly the two targets');
        $this->assertSame(2, $this->invoices->cancelUnpaidForDeadServices());

        $statusOf = static fn (int $id): string => (string) ($this->invoices->find($id)['status'] ?? 'missing');

        $this->assertSame('cancelled', $statusOf($a));
        $this->assertSame('cancelled', $statusOf($b));
        $this->assertSame('unpaid', $statusOf($c), 'an active service\'s invoice is live debt');
        $this->assertSame('unpaid', $statusOf($d), 'a suspended service\'s invoice is live debt');
        $this->assertSame('unpaid', $statusOf($e), 'an invoice with no service is never touched');
        $this->assertSame('paid', $statusOf($f), 'paid history is never rewritten');
        $this->assertSame('refunded', $statusOf($g), 'refunds are never rewritten');
        $this->assertSame('cancelled', $statusOf($h));

        // The two targets carry the cancellation bookkeeping the single-invoice
        // path (markCancelled) writes, so client-facing lists treat them the same.
        $cancelledInvoice = $this->invoices->find($a);
        $this->assertSame(1, (int) $cancelledInvoice['is_cancelled']);
        $this->assertNotNull($cancelledInvoice['cancelled_at']);
    }

    public function test_idempotent_rerun_cancels_nothing_new(): void
    {
        $terminated = $this->createService('terminated');
        $this->createInvoice($terminated);
        $this->createInvoice($terminated);

        $this->assertSame(2, $this->invoices->cancelUnpaidForDeadServices());
        $this->assertSame(0, $this->invoices->countUnpaidForDeadServices(), 'the preview drops to zero after the run');
        $this->assertSame(0, $this->invoices->cancelUnpaidForDeadServices(), 'a second click cancels nothing');
    }

    public function test_nothing_to_cancel_is_a_clean_zero(): void
    {
        $active = $this->createService('active');
        $this->createInvoice($active);
        $this->createInvoice(null);

        $this->assertSame(0, $this->invoices->countUnpaidForDeadServices());
        $this->assertSame(0, $this->invoices->cancelUnpaidForDeadServices());
    }
}
