<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\PaymentService;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class PaymentServiceTest extends DatabaseTestCase
{
    private PaymentService $payments;
    private InvoiceRepository $invoices;
    private TransactionRepository $transactions;
    private HookDispatcher $hooks;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->invoices = new InvoiceRepository($this->db);
        $this->transactions = new TransactionRepository($this->db);
        $this->hooks = new HookDispatcher();
        $this->payments = new PaymentService($this->invoices, $this->transactions, $this->hooks);

        $clients = new ClientRepository($this->db);
        $clientId = $clients->create([
            'email' => 'buyer@example.test',
            'password' => 'secret123',
            'first_name' => 'Buyer',
            'last_name' => 'Person',
        ]);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'unpaid', 50.00, 50.00, substr($now, 0, 10), $now, $now]
        );
    }

    public function test_full_payment_marks_invoice_paid(): void
    {
        $paidHook = [];
        $this->hooks->register(HookPoints::INVOICE_PAID, function (array $p) use (&$paidHook) {
            $paidHook[] = $p;
        });

        $result = $this->payments->recordPayment($this->invoiceId, 'manual', 50.00);

        $this->assertTrue($result['invoicePaid']);
        $this->assertSame('paid', $this->invoices->find($this->invoiceId)['status']);
        $this->assertNotNull($this->invoices->find($this->invoiceId)['paid_at']);
        $this->assertCount(1, $paidHook);
    }

    public function test_partial_payment_does_not_mark_invoice_paid(): void
    {
        $result = $this->payments->recordPayment($this->invoiceId, 'manual', 20.00);

        $this->assertFalse($result['invoicePaid']);
        $this->assertSame('unpaid', $this->invoices->find($this->invoiceId)['status']);
    }

    public function test_two_partial_payments_together_cover_the_total(): void
    {
        $this->payments->recordPayment($this->invoiceId, 'manual', 20.00);
        $result = $this->payments->recordPayment($this->invoiceId, 'manual', 30.00);

        $this->assertTrue($result['invoicePaid']);
        $this->assertSame('paid', $this->invoices->find($this->invoiceId)['status']);
        $this->assertCount(2, $this->transactions->forInvoice($this->invoiceId));
    }

    public function test_already_paid_invoice_is_not_reprocessed(): void
    {
        $this->payments->recordPayment($this->invoiceId, 'manual', 50.00);

        $result = $this->payments->recordPayment($this->invoiceId, 'manual', 10.00);

        // A second payment against an already-paid invoice still records
        // the transaction (e.g. a tip/overpayment) but doesn't re-fire the
        // paid transition, since the invoice was already marked paid.
        $this->assertFalse($result['invoicePaid']);
    }
}
