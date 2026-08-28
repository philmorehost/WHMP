<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\RecurringInvoiceRepository;
use CodeVault\Billing\RecurringInvoiceService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

final class RecurringInvoiceTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private InvoiceRepository $invoices;
    private RecurringInvoiceRepository $recurring;
    private RecurringInvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->recurring = new RecurringInvoiceRepository($this->db);
        $this->service = new RecurringInvoiceService($this->recurring, $this->invoices, $this->db);
    }

    private function clientId(): int
    {
        return $this->clients->create([
            'email' => 'recurring@example.test',
            'password' => 'secret123',
            'first_name' => 'Recur',
            'last_name' => 'Ring',
        ]);
    }

    private function items(): array
    {
        return [
            ['description' => 'Monthly retainer', 'amount' => 50.0],
            ['description' => 'Support plan', 'amount' => 25.0],
        ];
    }

    public function test_create_from_admin_creates_template_and_first_invoice(): void
    {
        $clientId = $this->clientId();

        $result = $this->service->createFromAdmin($clientId, $this->items(), 'monthly', 7, null, 1.0, 1);

        $ri = $this->recurring->find($result['recurring_id']);
        $this->assertNotNull($ri);
        $this->assertSame('monthly', $ri['billing_cycle']);
        $this->assertSame(75.0, (float) $ri['amount']);
        $this->assertSame('active', $ri['status']);
        $this->assertCount(2, $ri['items']);
        // next_due_date defaults to one cycle out.
        $this->assertSame(date('Y-m-d', strtotime('+1 month')), $ri['next_due_date']);

        // First invoice raised immediately, linked to the template.
        $invoice = $this->invoices->find($result['invoice_id']);
        $this->assertNotNull($invoice);
        $this->assertSame(75.0, (float) $invoice['total']);
        $this->assertSame($result['recurring_id'], (int) $invoice['recurring_invoice_id']);
        $this->assertCount(2, $this->invoices->items($result['invoice_id']));
    }

    public function test_generate_due_raises_the_next_invoice_and_advances_the_cycle(): void
    {
        $clientId = $this->clientId();
        $result = $this->service->createFromAdmin($clientId, $this->items(), 'monthly', 7, null, 1.0, 1, '2026-01-15');

        $generated = $this->service->generateDue('2026-01-15');

        $this->assertCount(1, $generated);

        $invoice = $this->invoices->find($generated[0]);
        $this->assertSame($result['recurring_id'], (int) $invoice['recurring_invoice_id']);
        // Generated invoice is due the day its cycle comes due.
        $this->assertSame('2026-01-15', $invoice['due_date']);
        $this->assertSame(75.0, (float) $invoice['total']);

        // Template rolled forward one month.
        $ri = $this->recurring->find($result['recurring_id']);
        $this->assertSame('2026-02-15', $ri['next_due_date']);
    }

    public function test_generate_due_is_idempotent_for_the_same_cycle(): void
    {
        $clientId = $this->clientId();
        $this->service->createFromAdmin($clientId, $this->items(), 'monthly', 7, null, 1.0, 1, '2026-01-15');

        $this->service->generateDue('2026-01-15');
        // A second run on the same day must not raise a duplicate invoice.
        $second = $this->service->generateDue('2026-01-15');

        $this->assertSame([], $second);
        // Initial invoice + one generated = 2, never 3.
        $this->assertSame(2, $this->invoices->paginate(null, 1, 100)['total']);
    }

    public function test_generate_due_skips_paused_and_cancelled_templates(): void
    {
        $clientId = $this->clientId();
        $a = $this->service->createFromAdmin($clientId, [['description' => 'A', 'amount' => 10.0]], 'monthly', 0, null, 1.0, 1, '2026-01-15');
        $b = $this->service->createFromAdmin($clientId, [['description' => 'B', 'amount' => 20.0]], 'monthly', 0, null, 1.0, 1, '2026-01-15');

        $this->recurring->setStatus($a['recurring_id'], 'paused');
        $this->recurring->setStatus($b['recurring_id'], 'cancelled');

        $this->assertSame([], $this->service->generateDue('2026-01-15'));
    }

    public function test_next_due_date_advances_per_cycle(): void
    {
        $this->assertSame('2026-02-15', $this->service->nextDueDate('2026-01-15', 'monthly'));
        $this->assertSame('2026-04-15', $this->service->nextDueDate('2026-01-15', 'quarterly'));
        $this->assertSame('2026-07-15', $this->service->nextDueDate('2026-01-15', 'semi_annually'));
        $this->assertSame('2027-01-15', $this->service->nextDueDate('2026-01-15', 'annually'));
        $this->assertSame('2028-01-15', $this->service->nextDueDate('2026-01-15', 'biennially'));
        $this->assertSame('2029-01-15', $this->service->nextDueDate('2026-01-15', 'triennially'));
    }

    public function test_invalid_next_due_date_falls_back_to_one_cycle_out(): void
    {
        $clientId = $this->clientId();
        // "not-a-date" is rejected by the service's validator.
        $result = $this->service->createFromAdmin($clientId, $this->items(), 'quarterly', 7, null, 1.0, 1, 'not-a-date');

        $ri = $this->recurring->find($result['recurring_id']);
        $this->assertSame(date('Y-m-d', strtotime('+3 months')), $ri['next_due_date']);
    }
}
