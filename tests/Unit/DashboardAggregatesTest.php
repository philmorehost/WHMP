<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\OrderRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainRepository;
use CodeVault\Reports\ReportRepository;
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
    private ReportRepository $reports;
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
        $this->reports = new ReportRepository($this->db);

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

    public function test_a_netted_reseller_cost_invoice_is_not_counted_as_cash_collected(): void
    {
        $now = new DateTimeImmutable();
        $stamp = $now->format('Y-m-d H:i:s');

        // Cash we actually received.
        $ordinary = $this->insertInvoice('paid', 100.00, $now->format('Y-m-d'));
        $this->db->update('UPDATE invoices SET paid_at = ? WHERE id = ?', [$stamp, $ordinary]);

        // A reseller's store-cost invoice. It is marked paid because it is settled
        // from their running account rather than by a payment, so NO cash changes
        // hands on it — see ResellerCostBillingJob.
        $cost = $this->insertInvoice('paid', 400.00, $now->format('Y-m-d'));
        $this->db->update('UPDATE invoices SET paid_at = ? WHERE id = ?', [$stamp, $cost]);
        $this->linkAsResellerCostInvoice($cost, 400.0);

        // Only the cash invoice. Counting the cost invoice too would report ONE store
        // sale twice: the customer's retail invoice and the reseller's cost invoice.
        $this->assertEqualsWithDelta(100.00, $this->invoices->totalPaidThisMonth(), 0.001);

        // The grouped income figure (and the AI insight built on it) must agree with
        // the scalar one, or the dashboard and the widget quote different incomes.
        $grouped = 0.0;
        foreach ($this->invoices->paidThisMonthByCurrency() as $row) {
            $grouped += (float) $row['amount'];
        }
        $this->assertEqualsWithDelta(100.00, $grouped, 0.001);

        // Negative control: the LINK is what excludes it, not the row being absent or
        // the status being ignored. Unlink it and the same invoice IS counted — which
        // is also what the dashboard did before cost invoices were settled at all.
        $this->db->update('UPDATE orders SET reseller_cost_invoice_id = NULL WHERE reseller_cost_invoice_id = ?', [$cost]);

        $this->assertEqualsWithDelta(500.00, $this->invoices->totalPaidThisMonth(), 0.001);
    }

    public function test_the_revenue_chart_agrees_with_the_income_tile_about_cost_invoices(): void
    {
        // THE BUG THIS PINS: the chart and the tile are on the SAME SCREEN.
        //
        // The dashboard's income tile excluded a reseller's store-cost invoice and the
        // revenue chart did not, so the bar for a month with cost billing ran high by
        // the cost of every store sale in it — two figures for the same month, one
        // page, and only one of them right. Someone would eventually notice and have
        // no way to tell which to trust, which is what makes a wrong figure worse on a
        // page that also shows a right one.
        //
        // Asserting the two AGAINST EACH OTHER is the whole point. Asserting either on
        // its own is precisely how they diverged: each was individually defensible,
        // and the missing piece was a figure neither test compared itself to.
        $now = new DateTimeImmutable();
        $stamp = $now->format('Y-m-d H:i:s');

        $ordinary = $this->insertInvoice('paid', 100.00, $now->format('Y-m-d'));
        $this->db->update('UPDATE invoices SET paid_at = ? WHERE id = ?', [$stamp, $ordinary]);

        // A reseller's store-cost invoice: paid, but no cash ever changed hands on it.
        $cost = $this->insertInvoice('paid', 400.00, $now->format('Y-m-d'));
        $this->db->update('UPDATE invoices SET paid_at = ? WHERE id = ?', [$stamp, $cost]);
        $this->linkAsResellerCostInvoice($cost, 400.0);

        $chartTotal = 0.0;
        $thisMonth = $now->format('Y-m');

        foreach ($this->reports->incomeByMonth((int) $now->format('Y')) as $row) {
            if ($row['month'] === $thisMonth) {
                $chartTotal += (float) $row['total'];
            }
        }

        $this->assertEqualsWithDelta(
            $this->invoices->totalPaidThisMonth(),
            $chartTotal,
            0.001,
            'the chart bar and the income tile are the same figure and must never disagree'
        );

        // And the shared answer is the right one: the cash invoice alone.
        $this->assertEqualsWithDelta(100.00, $chartTotal, 0.001);
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

    /**
     * Make an invoice the cost invoice for a store order, which is what marks it as
     * netted. The order carries the cost; the invoice is what it was billed as.
     */
    private function linkAsResellerCostInvoice(int $invoiceId, float $cost): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->insert(
            'INSERT INTO orders (client_id, status, total, cost_total, reseller_cost_invoice_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'active', $cost + 100.0, $cost, $invoiceId, $now, $now]
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
