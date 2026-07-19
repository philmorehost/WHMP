<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ClientCreditRepository;
use CodeVault\Billing\CreditService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\PaymentService;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class CreditServiceTest extends DatabaseTestCase
{
    private ClientCreditRepository $credit;
    private CreditService $creditService;
    private InvoiceRepository $invoices;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->credit = new ClientCreditRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $hooks = new HookDispatcher();
        $transactions = new TransactionRepository($this->db);
        $payments = new PaymentService($this->invoices, $transactions, $hooks);
        $this->creditService = new CreditService($this->credit, $this->invoices, $transactions, $payments, $hooks);

        $clients = new ClientRepository($this->db);
        $this->clientId = $clients->create([
            'email' => 'creditor@example.test',
            'password' => 'secret123',
            'first_name' => 'Cred',
            'last_name' => 'Itor',
        ]);
    }

    private function createInvoice(float $total): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'unpaid', $total, $total, substr($now, 0, 10), $now, $now]
        );
    }

    public function test_grant_increases_balance(): void
    {
        $this->creditService->grant($this->clientId, 25.00, 'Goodwill credit');

        $this->assertSame(25.00, $this->credit->balance($this->clientId));
    }

    public function test_balance_is_the_sum_of_the_ledger_not_a_stored_column(): void
    {
        $this->creditService->grant($this->clientId, 25.00, 'Grant 1');
        $this->creditService->grant($this->clientId, 10.00, 'Grant 2');

        $this->assertSame(35.00, $this->credit->balance($this->clientId));
        $this->assertCount(2, $this->credit->forClient($this->clientId));
    }

    public function test_apply_to_invoice_fully_covers_a_smaller_invoice(): void
    {
        $this->creditService->grant($this->clientId, 50.00, 'Grant');
        $invoiceId = $this->createInvoice(20.00);

        $result = $this->creditService->applyToInvoice($this->clientId, $invoiceId);

        $this->assertTrue($result['success']);
        $this->assertSame(20.00, $result['applied']);
        $this->assertSame('paid', $this->invoices->find($invoiceId)['status']);
        $this->assertSame(30.00, $this->credit->balance($this->clientId));
    }

    public function test_apply_to_invoice_after_a_prior_partial_payment_only_covers_the_true_remainder(): void
    {
        // Regression test: applying credit must account for payments
        // already recorded against this invoice via another gateway —
        // not the raw invoice total — or it double-counts what's owed.
        $invoiceId = $this->createInvoice(20.00);
        $payments = new PaymentService($this->invoices, new TransactionRepository($this->db), new HookDispatcher());
        $payments->recordPayment($invoiceId, 'manual', 12.00); // client already paid $12 of $20

        $this->creditService->grant($this->clientId, 100.00, 'Large grant');
        $result = $this->creditService->applyToInvoice($this->clientId, $invoiceId);

        $this->assertTrue($result['success']);
        $this->assertSame(8.00, $result['applied'], 'only the remaining $8 should be drawn from credit, not the full $20');
        $this->assertSame(92.00, $this->credit->balance($this->clientId));
        $this->assertSame('paid', $this->invoices->find($invoiceId)['status']);
    }

    public function test_apply_to_invoice_partially_covers_a_larger_invoice(): void
    {
        $this->creditService->grant($this->clientId, 15.00, 'Grant');
        $invoiceId = $this->createInvoice(50.00);

        $result = $this->creditService->applyToInvoice($this->clientId, $invoiceId);

        $this->assertTrue($result['success']);
        $this->assertSame(15.00, $result['applied']);
        $this->assertSame('unpaid', $this->invoices->find($invoiceId)['status']);
        $this->assertSame(0.0, $this->credit->balance($this->clientId));
    }

    public function test_apply_to_invoice_fails_with_no_credit(): void
    {
        $invoiceId = $this->createInvoice(50.00);

        $result = $this->creditService->applyToInvoice($this->clientId, $invoiceId);

        $this->assertFalse($result['success']);
    }

    public function test_cannot_apply_credit_to_another_clients_invoice(): void
    {
        $clients = new ClientRepository($this->db);
        $otherClientId = $clients->create([
            'email' => 'other@example.test',
            'password' => 'secret123',
            'first_name' => 'Other',
            'last_name' => 'Client',
        ]);

        $this->creditService->grant($this->clientId, 50.00, 'Grant');
        $invoiceId = $this->createInvoice(20.00);

        $result = $this->creditService->applyToInvoice($otherClientId, $invoiceId);

        $this->assertFalse($result['success']);
    }
}
