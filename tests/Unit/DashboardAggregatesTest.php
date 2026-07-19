<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\OrderRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainRepository;
use CodeVault\Reports\SvgChartRenderer;
use CodeVault\Support\TicketRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * Covers the lightweight COUNT/SUM aggregate methods added for the R17
 * dashboard rebuild — each is a bare aggregate query, not the "fetch a full
 * row set just to count() it" pattern the old dashboard used.
 */
final class DashboardAggregatesTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private OrderRepository $orders;
    private InvoiceRepository $invoices;
    private TicketRepository $tickets;
    private ServiceRepository $services;
    private DomainRepository $domains;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->orders = new OrderRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->tickets = new TicketRepository($this->db);
        $this->services = new ServiceRepository($this->db);
        $this->domains = new DomainRepository($this->db);

        $this->clientId = $this->clients->create([
            'email' => 'dashboard-subject@example.test',
            'password' => 'whatever123',
            'first_name' => 'Dash',
            'last_name' => 'Board',
        ]);
    }

    public function test_order_count_pending_ignores_other_statuses(): void
    {
        $this->insertOrder('pending');
        $this->insertOrder('active');
        $this->insertOrder('cancelled');

        $this->assertSame(1, $this->orders->countPending());
    }

    public function test_invoice_overdue_count_and_sum(): void
    {
        $today = new DateTimeImmutable();
        $this->insertInvoice('unpaid', 50.00, $today->modify('-3 days')->format('Y-m-d'));
        $this->insertInvoice('unpaid', 25.00, $today->modify('-1 day')->format('Y-m-d'));
        $this->insertInvoice('unpaid', 10.00, $today->modify('+5 days')->format('Y-m-d')); // not yet due
        $this->insertInvoice('paid', 999.00, $today->modify('-3 days')->format('Y-m-d')); // paid, excluded

        $this->assertSame(2, $this->invoices->countOverdue());
        $this->assertEqualsWithDelta(75.00, $this->invoices->sumOverdue(), 0.001);
    }

    public function test_invoice_total_paid_this_month_excludes_prior_months(): void
    {
        $id = $this->insertInvoice('paid', 100.00, (new DateTimeImmutable())->format('Y-m-d'));
        $this->db->update('UPDATE invoices SET paid_at = ? WHERE id = ?', [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);

        $oldId = $this->insertInvoice('paid', 500.00, '2000-01-01');
        $this->db->update('UPDATE invoices SET paid_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $oldId]);

        $this->assertEqualsWithDelta(100.00, $this->invoices->totalPaidThisMonth(), 0.001);
    }

    public function test_ticket_count_open_excludes_closed(): void
    {
        $deptId = $this->insertDepartment();
        $this->insertTicket($deptId, 'open');
        $this->insertTicket($deptId, 'customer-reply');
        $this->insertTicket($deptId, 'closed');

        $this->assertSame(2, $this->tickets->countOpen());
    }

    public function test_service_count_due_for_billing_respects_window(): void
    {
        $productId = $this->insertProduct();
        $today = new DateTimeImmutable();
        $this->insertService($productId, 'active', $today->modify('+3 days')->format('Y-m-d'));
        $this->insertService($productId, 'active', $today->modify('+30 days')->format('Y-m-d'));

        $this->assertSame(1, $this->services->countDueForBilling(7));
    }

    public function test_domain_count_due_for_renewal_requires_auto_renew(): void
    {
        $today = new DateTimeImmutable();
        $this->insertDomain('active', 1, $today->modify('+3 days')->format('Y-m-d'));
        $this->insertDomain('active', 0, $today->modify('+3 days')->format('Y-m-d')); // auto_renew off, excluded

        $this->assertSame(1, $this->domains->countDueForRenewal(7));
    }

    public function test_svg_chart_renders_a_bar_per_point_with_a_valid_viewbox(): void
    {
        $svg = (new SvgChartRenderer())->bar([
            ['label' => 'Jan', 'value' => 100.0],
            ['label' => 'Feb', 'value' => 0.0],
            ['label' => 'Mar', 'value' => 250.5],
        ]);

        $this->assertStringContainsString('viewBox="0 0 640 220"', $svg);
        $this->assertSame(3, substr_count($svg, 'cv-chart__bar'));
        $this->assertStringContainsString('Mar: 250.50', $svg);
    }

    public function test_svg_chart_handles_an_empty_series_without_error(): void
    {
        $svg = (new SvgChartRenderer())->bar([]);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('cv-chart__bar', $svg);
    }

    private function insertOrder(string $status): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$this->clientId, $status, $now, $now]
        );
    }

    private function insertInvoice(string $status, float $total, string $dueDate): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $status, $total, $total, $dueDate, $now, $now]
        );
    }

    private function insertDepartment(): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert('INSERT INTO departments (name, created_at, updated_at) VALUES (?, ?, ?)', ['Support', $now, $now]);
    }

    private function insertTicket(int $deptId, string $status): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO tickets (client_id, email, department_id, subject, status, priority, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'dashboard-subject@example.test', $deptId, 'Subject', $status, 'medium', $now, $now]
        );
    }

    private function insertProduct(): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $groupId = (int) $this->db->insert('INSERT INTO product_groups (name, created_at, updated_at) VALUES (?, ?, ?)', ['Hosting', $now, $now]);

        return (int) $this->db->insert('INSERT INTO products (product_group_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)', [$groupId, 'Starter', $now, $now]);
    }

    private function insertService(int $productId, string $status, string $nextDueDate): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $productId, 'Starter', 'monthly', 9.99, $status, $nextDueDate, $now, $now]
        );
    }

    private function insertDomain(string $status, int $autoRenew, string $nextDueDate): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO domains (client_id, domain_name, tld, registrar_slug, status, next_due_date, auto_renew, amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'example' . uniqid(), 'com', 'local', $status, $nextDueDate, $autoRenew, 12.00, $now, $now]
        );
    }
}
