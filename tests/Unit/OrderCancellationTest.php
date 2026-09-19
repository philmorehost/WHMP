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

        // EmailDispatcher reads branding settings on every send, resolving its
        // SettingsRepository from the container. Pin that to the test database
        // as well: otherwise the notification path reaches for the
        // application's configured database, which this environment has no
        // grants for, and the whole cancellation blows up in the email step —
        // before the assertions about the cascade ever run.
        $container->instance(
            \CodeVault\Settings\SettingsRepository::class,
            new \CodeVault\Settings\SettingsRepository($this->db)
        );

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
            new InvoiceRepository($this->db),
            new \CodeVault\Billing\ServiceRepository($this->db),
            new \CodeVault\Domains\DomainRepository($this->db)
        );
    }

    private function createService(int $orderId, string $status = 'active'): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $groupId = (int) $this->db->insert(
            'INSERT INTO product_groups (name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['Order Cancel Group', 0, $now, $now]
        );
        $productId = (int) $this->db->insert(
            'INSERT INTO products (product_group_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$groupId, 'Order Cancel Product', $now, $now]
        );

        return (int) $this->db->insert(
            "INSERT INTO services (client_id, order_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, 'monthly', ?, ?, ?, ?, ?)",
            [$this->clientId, $orderId, $productId, 'Order Cancel Product', 9.99, $status, $now, $now, $now]
        );
    }

    private function createDomain(int $orderId, string $status = 'active'): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO domains (client_id, order_id, domain_name, tld, registrar_slug, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $orderId, 'ordercancel' . uniqid() . '.test', 'test', 'local', $status, $now, $now]
        );
    }

    private function statusOf(string $table, int $id): string
    {
        return (string) $this->db->selectOne("SELECT status FROM {$table} WHERE id = ?", [$id])['status'];
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

    /**
     * Cancelling an order has to move everything the order created, not just
     * the order row — a cancelled order with live services and a payable
     * invoice is a state nobody can reason about.
     */
    public function test_client_cancel_order_cancels_its_services_and_domains(): void
    {
        $orderId = $this->createOrder('pending');
        $serviceId = $this->createService($orderId);
        $domainId = $this->createDomain($orderId);

        $ok = $this->service()->clientCancelOrder($orderId, $this->clientId, 'Changed my mind');

        $this->assertTrue($ok);
        $this->assertSame('cancelled', $this->statusOf('services', $serviceId));
        $this->assertSame('cancelled', $this->statusOf('domains', $domainId));
    }

    /**
     * The admin Cancel button used to touch only the order, so its unpaid
     * invoice stayed payable. The service reports the whole cascade back.
     */
    public function test_admin_cancel_cancels_the_invoice_and_services_and_reports_them(): void
    {
        $orderId = $this->createOrder('pending');
        $invoiceId = $this->createInvoice($orderId, 'unpaid');
        $this->createService($orderId);
        $this->createDomain($orderId);

        $result = $this->service()->cancelOrder($orderId, 'Cancelled by admin');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['invoiceCancelled']);
        $this->assertSame($invoiceId, $result['invoiceId']);
        $this->assertSame(1, $result['services']);
        $this->assertSame(1, $result['domains']);
        $this->assertSame('cancelled', $this->statusOf('invoices', $invoiceId));
    }

    public function test_cancelling_an_already_cancelled_order_is_a_no_op(): void
    {
        $orderId = $this->createOrder('pending');

        $this->assertTrue($this->service()->cancelOrder($orderId, 'First')['success']);

        $second = $this->service()->cancelOrder($orderId, 'Second');

        $this->assertFalse($second['success']);
        $this->assertSame('already-cancelled', $second['reason']);
    }

    /**
     * The inverse of the cascade: order back to pending, invoice payable
     * again, and the services/domains the cancellation closed back to
     * pending so acceptance can provision them.
     */
    public function test_reactivating_an_order_restores_the_invoice_and_services(): void
    {
        $orderId = $this->createOrder('pending');
        $invoiceId = $this->createInvoice($orderId, 'unpaid');
        $serviceId = $this->createService($orderId);
        $domainId = $this->createDomain($orderId);

        $this->service()->cancelOrder($orderId, 'Mistake');
        $this->assertSame('cancelled', $this->statusOf('invoices', $invoiceId));

        $result = $this->service()->reactivateOrder($orderId);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['invoiceReactivated']);
        $this->assertSame(1, $result['services']);
        $this->assertSame(1, $result['domains']);
        $this->assertSame('pending', (new OrderRepository($this->db))->findById($orderId)['status']);
        $this->assertSame('unpaid', $this->statusOf('invoices', $invoiceId));
        $this->assertSame('pending', $this->statusOf('services', $serviceId));
        $this->assertSame('pending', $this->statusOf('domains', $domainId));
    }

    public function test_client_can_reactivate_their_own_order_but_not_another_clients(): void
    {
        $orderId = $this->createOrder('pending');
        $this->service()->cancelOrder($orderId, 'Mistake');

        $this->assertFalse($this->service()->clientReactivateOrder($orderId, 999999));
        $this->assertTrue($this->service()->clientReactivateOrder($orderId, $this->clientId));
    }

    public function test_reactivating_an_order_that_was_not_cancelled_changes_nothing(): void
    {
        $orderId = $this->createOrder('pending');

        $result = $this->service()->reactivateOrder($orderId);

        $this->assertFalse($result['success']);
        $this->assertSame('not-cancelled', $result['reason']);
        $this->assertSame('pending', (new OrderRepository($this->db))->findById($orderId)['status']);
    }

    /**
     * A terminated service is gone on the remote server; an order
     * cancellation must not pretend otherwise by marking it cancelled (which
     * reactivation would then flip back to pending).
     */
    public function test_a_terminated_service_is_left_alone_by_a_cancellation(): void
    {
        $orderId = $this->createOrder('pending');
        $serviceId = $this->createService($orderId, 'terminated');

        $result = $this->service()->cancelOrder($orderId, 'Cancelled by admin');

        $this->assertSame(0, $result['services']);
        $this->assertSame('terminated', $this->statusOf('services', $serviceId));
    }
}
