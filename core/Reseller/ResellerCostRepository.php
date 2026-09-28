<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;

/**
 * Reads and writes what a store owes us for the orders it took.
 *
 * Deliberately dumb SQL: it does not convert anything between currencies,
 * because conversion is a money rule and money rules live in
 * ResellerCostService (the same split as ResellerPricing / its repository).
 *
 * cost_total is stored in the CUSTOMER's order currency, so every total this
 * class returns is grouped by (currency_id, currency_rate) and the caller is
 * expected to convert. Two orders in the same currency can still need different
 * arithmetic — a denominated row (rate 1.0, amount already converted) versus a
 * locked one (rate != 1.0, amount in base) — which is why currency_rate is part
 * of the grouping key, not just currency_id.
 */
final class ResellerCostRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Order cost that has not been billed yet and belongs to a closed period,
     * oldest first so month buckets come out in order without re-sorting.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unbilledBefore(string $cutoff): array
    {
        return $this->db->select(
            'SELECT id, reseller_id, client_id, cost_total, currency_id, currency_rate, created_at
             FROM orders
             WHERE reseller_id IS NOT NULL
               AND reseller_cost_invoice_id IS NULL
               AND cost_total IS NOT NULL
               AND created_at < ?
             ORDER BY reseller_id ASC, created_at ASC, id ASC',
            [$cutoff]
        );
    }

    /**
     * Accrued cost, one row per (store, currency, storage convention, billed).
     *
     * `unbilled` is 1 when the order's cost has not been invoiced yet. Summing
     * across the rows gives per-currency totals; the service converts them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function totals(?int $resellerId = null): array
    {
        $where = 'reseller_id IS NOT NULL AND cost_total IS NOT NULL';
        $bindings = [];

        if ($resellerId !== null) {
            $where .= ' AND reseller_id = ?';
            $bindings[] = $resellerId;
        }

        return $this->db->select(
            "SELECT reseller_id,
                    currency_id,
                    currency_rate,
                    (reseller_cost_invoice_id IS NULL) AS unbilled,
                    COUNT(*) AS order_count,
                    SUM(cost_total) AS cost_total
             FROM orders
             WHERE {$where}
             GROUP BY reseller_id, currency_id, currency_rate, unbilled
             ORDER BY reseller_id ASC, currency_id ASC",
            $bindings
        );
    }

    /**
     * Cost invoices that are raised but not paid — the reseller's arrears.
     *
     * Found by following orders.reseller_cost_invoice_id rather than by a flag
     * on the invoice, so there is exactly one place that decides what a cost
     * invoice is.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unpaidCostInvoices(?int $resellerId = null): array
    {
        $where = "i.status = 'unpaid'";
        $bindings = [];

        if ($resellerId !== null) {
            $where .= ' AND o.reseller_id = ?';
            $bindings[] = $resellerId;
        }

        return $this->db->select(
            "SELECT i.id,
                    i.client_id,
                    i.status,
                    i.total,
                    i.currency_id,
                    i.currency_rate,
                    i.due_date,
                    COUNT(o.id) AS order_count,
                    MIN(o.reseller_id) AS reseller_id
             FROM invoices i
             JOIN orders o ON o.reseller_cost_invoice_id = i.id
             WHERE {$where}
             GROUP BY i.id, i.client_id, i.status, i.total, i.currency_id, i.currency_rate, i.due_date
             ORDER BY i.due_date ASC, i.id ASC",
            $bindings
        );
    }

    /**
     * The invoice that billed a given order's cost, if any.
     *
     * @return array<string, mixed>|null
     */
    public function invoiceForOrder(int $orderId): ?array
    {
        return $this->db->selectOne(
            'SELECT i.* FROM invoices i JOIN orders o ON o.reseller_cost_invoice_id = i.id WHERE o.id = ?',
            [$orderId]
        );
    }

    /**
     * How many distinct invoices have billed cost so far.
     *
     * Lets a manual billing run report what it actually raised by comparing
     * before and after — CronJob::handle() is void by contract, so the job
     * cannot return the number itself.
     */
    public function distinctCostInvoiceCount(): int
    {
        return (int) ($this->db->selectOne(
            'SELECT COUNT(DISTINCT reseller_cost_invoice_id) AS c FROM orders WHERE reseller_cost_invoice_id IS NOT NULL'
        )['c'] ?? 0);
    }

    /**
     * Stamp orders as billed by one invoice.
     *
     * The `AND reseller_cost_invoice_id IS NULL` guard is inside the UPDATE, not
     * tested before it: the affected-row count is then the authority on what was
     * claimed, so two runs racing cannot bill the same order twice.
     *
     * @param array<int, int> $orderIds
     * @return int orders actually claimed
     */
    public function markBilled(array $orderIds, int $invoiceId): int
    {
        if ($orderIds === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));

        return $this->db->update(
            "UPDATE orders SET reseller_cost_invoice_id = ?
             WHERE id IN ({$placeholders}) AND reseller_cost_invoice_id IS NULL",
            array_merge([$invoiceId], array_map('intval', $orderIds))
        );
    }
}
