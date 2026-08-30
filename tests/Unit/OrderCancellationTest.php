<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\OrderCancellationService;
use CodeVault\Billing\OrderRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\Mailer;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * A client cancelling a pending/ongoing order must have the order marked
 * cancelled AND the invoice the order raised cancelled, so they are never
 * billed for an order they no longer want.
 */
final class OrderCancellationTest extends DatabaseTestCase
{
    private int $clientId;
    /** @var array<int, array{to: string, subject: string, html: string}> */
    private array $sentMails = [];

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        $container = App::container();
        $container->instance(Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

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

        $this->clientId = (new ClientRepository($this->db))->create([
            'email' => 'ordercancel@example.test',
            'password' => 'secret123',
            'first_name' => 'Order',
            'last_name' => 'Cancel',
        ]);
    }

    private function service(): OrderCancellationService
    {
        return new OrderCancellationService(
            new OrderRepository($this->db),
            App::container()->make(EmailDispatcher::class),
            $this->db,
            new InvoiceRepository($this->db)
        );
    }

    private function createOrder(string $status = 'pending'): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, discount_amount, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, 1.0, ?, ?)',
            [$this->clientId, $status, 52.99, 0.0, $now, $now]
        );
    }

    private function createInvoice(int $orderId, string $status): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $orderId, $status, 52.99, 0.0, 52.99, '2026-09-01', $now, $now]
        );
    }

    public function test_client_cancel_order_marks_order_and_invoice_cancelled(): void
    {
        $orderId = $this->createOrder('pending');
        $invoiceId = $this->createInvoice($orderId, 'unpaid');

        $ok = $this->service()->clientCancelOrder($orderId, $this->clientId, 'Changed my mind');

        $this->assertTrue($ok);
        $this->assertSame('cancelled', (new OrderRepository($this->db))->findById($orderId)['status']);
        $this->assertSame('cancelled', (new InvoiceRepository($this->db))->find($invoiceId)['status']);
    }

    public function test_client_cannot_cancel_another_clients_order(): void
    {
        $orderId = $this->createOrder('pending');

        $ok = $this->service()->clientCancelOrder($orderId, 999999, 'nope');
        $this->assertFalse($ok);
    }

    public function test_paid_invoice_is_not_cancelled_when_order_is_cancelled(): void
    {
        $orderId = $this->createOrder('active');
        $invoiceId = $this->createInvoice($orderId, 'paid');

        $ok = $this->service()->clientCancelOrder($orderId, $this->clientId, 'No longer needed');

        $this->assertTrue($ok);
        // A paid invoice must never be flipped to cancelled.
        $this->assertSame('paid', (new InvoiceRepository($this->db))->find($invoiceId)['status']);
    }
}
