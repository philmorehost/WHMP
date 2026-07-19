<?php

declare(strict_types=1);

namespace CodeVault\Reports;

use CodeVault\Database;

/**
 * Core built-in admin reports (blueprint §4.3) — plain aggregation
 * queries against tables that already exist, not a pluggable module
 * (that's ReportModule, for third-party/custom reports). Scoped to the
 * reports with the clearest, least ambiguous SQL: income, tax liability,
 * aged debtors, product breakdown (initial-order revenue), and affiliate
 * payouts.
 */
final class ReportRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array{month: string, total: float}> */
    public function incomeByMonth(int $year): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month, SUM(total) AS total
            FROM invoices
            WHERE status = 'paid' AND YEAR(paid_at) = ?
            GROUP BY DATE_FORMAT(paid_at, '%Y-%m')
            ORDER BY month
            SQL,
            [$year]
        );
    }

    /** @return array<int, array{gateway_slug: string, total: float}> */
    public function incomeByGateway(int $year): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT t.gateway_slug, SUM(t.amount) AS total
            FROM transactions t
            WHERE t.status = 'completed' AND YEAR(t.created_at) = ?
            GROUP BY t.gateway_slug
            ORDER BY total DESC
            SQL,
            [$year]
        );
    }

    /** @return array<int, array{month: string, tax_amount: float}> */
    public function taxLiabilityByMonth(int $year): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month, SUM(tax_amount) AS tax_amount
            FROM invoices
            WHERE status = 'paid' AND YEAR(paid_at) = ?
            GROUP BY DATE_FORMAT(paid_at, '%Y-%m')
            ORDER BY month
            SQL,
            [$year]
        );
    }

    /**
     * Unpaid invoices bucketed by days overdue (blueprint §4.3 "aged
     * debtors"). Bucketing happens in PHP rather than a SQL CASE so the
     * bucket boundaries stay easy to read/change in one place.
     *
     * @return array<string, array{label: string, invoices: array<int, array<string, mixed>>, total: float}>
     */
    public function agedDebtors(): array
    {
        $rows = $this->db->select(
            <<<'SQL'
            SELECT i.*, c.first_name, c.last_name, c.email AS client_email,
                   DATEDIFF(CURDATE(), i.due_date) AS days_overdue
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            WHERE i.status = 'unpaid'
            ORDER BY days_overdue DESC
            SQL
        );

        $buckets = [
            'current' => ['label' => 'Not yet due', 'invoices' => [], 'total' => 0.0],
            '1-30' => ['label' => '1-30 days overdue', 'invoices' => [], 'total' => 0.0],
            '31-60' => ['label' => '31-60 days overdue', 'invoices' => [], 'total' => 0.0],
            '61-90' => ['label' => '61-90 days overdue', 'invoices' => [], 'total' => 0.0],
            '90+' => ['label' => '90+ days overdue', 'invoices' => [], 'total' => 0.0],
        ];

        foreach ($rows as $row) {
            $days = (int) $row['days_overdue'];
            $key = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default => '90+',
            };

            $buckets[$key]['invoices'][] = $row;
            $buckets[$key]['total'] += (float) $row['total'];
        }

        return $buckets;
    }

    /**
     * Revenue by product from initial (order-time) purchases — renewal
     * revenue lives on services/invoices without a product_id join, so
     * this covers first-order revenue, not lifetime product revenue.
     *
     * @return array<int, array{product_name: string, quantity: int, revenue: float}>
     */
    public function productBreakdown(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT oi.product_name, SUM(oi.quantity) AS quantity, SUM(oi.unit_price * oi.quantity + oi.setup_fee) AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.status IN ('active', 'fraud', 'pending')
            GROUP BY oi.product_name
            ORDER BY revenue DESC
            SQL
        );
    }

    /** @return array<int, array{code: string, client_name: string, paid_total: float, pending_total: float}> */
    public function affiliatePayouts(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT
                a.code,
                CONCAT(c.first_name, ' ', c.last_name) AS client_name,
                COALESCE((SELECT SUM(amount) FROM affiliate_commissions WHERE affiliate_id = a.id AND status = 'paid'), 0) AS paid_total,
                COALESCE((SELECT SUM(amount) FROM affiliate_commissions WHERE affiliate_id = a.id AND status IN ('pending', 'requested')), 0) AS pending_total
            FROM affiliates a
            JOIN clients c ON c.id = a.client_id
            ORDER BY paid_total DESC
            SQL
        );
    }
}
