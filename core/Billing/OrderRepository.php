<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

final class OrderRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(?string $status = null): array
    {
        $where = $status !== null ? 'WHERE o.status = ?' : '';
        $bindings = $status !== null ? [$status] : [];

        // Currency resolves from the order's own locked currency first
        // (what the client was shown at checkout), then the client's saved
        // preference, then the system default — the same fallback chain as
        // InvoiceRepository::paginate(). Resolving purely from the client's
        // *current* currency mis-labels a historical order once that client
        // changes their default.
        return $this->db->select(
            <<<SQL
            SELECT o.*, c.email AS client_email, c.first_name, c.last_name, cu.code AS currency_code, cu.symbol AS currency_symbol
            FROM orders o
            JOIN clients c ON c.id = o.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(o.currency_id, c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            {$where}
            ORDER BY o.id DESC
            SQL,
            $bindings
        );
    }

    /**
     * Paginated, per-column-filterable order list for the admin Orders page.
     *
     * `$filters` is the sanitised `filters[]` bag (see Table\TableFilters)
     * with a fixed set of allowed keys mapped to SQL columns — every column
     * and value is bound, so nothing user-supplied reaches the query except
     * through a placeholder.
     *
     * @param array<string, string> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(?string $status = null, int $page = 1, int $perPage = 15, array $filters = [], ?array $sort = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $bindings = [];

        if ($status !== null) {
            $conditions[] = 'o.status = ?';
            $bindings[] = $status;
        }

        [$filterWhere, $filterBindings] = \CodeVault\Table\TableFilters::where($filters, [
            'id'     => ['o.id', 'number'],
            'client' => [['c.first_name', 'c.last_name', 'c.email'], 'like'],
            'total'  => ['o.total', 'number'],
            'status' => ['o.status', 'eq'],
        ]);

        if ($filterWhere !== '') {
            $conditions[] = $filterWhere;
            $bindings = array_merge($bindings, $filterBindings);
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        // WHMCS-style sortable columns; a null/unsortable sort keeps the
        // default newest-first ordering.
        $sortable = [
            'id'     => 'o.id',
            'client' => 'c.last_name',
            'total'  => 'o.total',
            'status' => 'o.status',
        ];
        $orderBy = \CodeVault\Table\TableFilters::orderBy($sortable, $sort);
        if ($orderBy === '') {
            $orderBy = 'ORDER BY o.id DESC';
        }

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS c FROM orders o JOIN clients c ON c.id = o.client_id {$where}",
            $bindings
        )['c'] ?? 0);

        $data = $this->db->select(
            <<<SQL
            SELECT o.*, c.email AS client_email, c.first_name, c.last_name, cu.code AS currency_code, cu.symbol AS currency_symbol,
                (SELECT i.id FROM invoices i WHERE i.order_id = o.id ORDER BY i.id DESC LIMIT 1) AS invoice_id,
                (SELECT i.status FROM invoices i WHERE i.order_id = o.id ORDER BY i.id DESC LIMIT 1) AS invoice_status,
                (SELECT i.paid_at FROM invoices i WHERE i.order_id = o.id ORDER BY i.id DESC LIMIT 1) AS invoice_paid_at,
                (SELECT t.gateway_slug FROM transactions t
                    WHERE t.invoice_id = (SELECT i2.id FROM invoices i2 WHERE i2.order_id = o.id ORDER BY i2.id DESC LIMIT 1)
                      AND t.status = 'completed'
                    ORDER BY t.id DESC LIMIT 1) AS payment_gateway
            FROM orders o
            JOIN clients c ON c.id = o.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(o.currency_id, c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            {$where}
            {$orderBy}
            LIMIT {$perPage} OFFSET {$offset}
            SQL,
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            <<<'SQL'
            SELECT o.*, c.email AS client_email, c.first_name, c.last_name, cu.code AS currency_code, cu.symbol AS currency_symbol
            FROM orders o
            JOIN clients c ON c.id = o.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(o.currency_id, c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            WHERE o.id = ?
            SQL,
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * A client's own orders (newest first) — powers the client "My Orders"
     * page where a pending/ongoing order can be cancelled from the portal.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forClient(int $clientId): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT o.*, c.email AS client_email, c.first_name, c.last_name, cu.code AS currency_code, cu.symbol AS currency_symbol
            FROM orders o
            JOIN clients c ON c.id = o.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(o.currency_id, c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            WHERE o.client_id = ?
            ORDER BY o.id DESC
            SQL,
            [$clientId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function items(int $orderId): array
    {
        return $this->db->select('SELECT * FROM order_items WHERE order_id = ?', [$orderId]);
    }

    public function accept(int $id): void
    {
        $this->db->update(
            'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
            ['active', (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function cancel(int $id): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE orders SET status = ?, is_cancelled = 1, cancelled_at = ?, updated_at = ? WHERE id = ?',
            ['cancelled', $now, $now, $id]
        );
    }

    /**
     * Puts a cancelled order back to pending. Only a cancelled order is
     * touched (an already-pending/active one is left alone) and the return
     * value says whether anything changed. The cancellation audit columns are
     * cleared so the order no longer reads as cancelled anywhere.
     *
     * Like invoices, a cancellation can be recorded in the `status` column
     * (the admin Cancel button) or the older `is_cancelled` audit flag
     * (OrderCancellationService, which also set status), so both are matched
     * and cleared. The caller reactivates the order's invoice separately.
     */
    public function reactivate(int $id): bool
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $affected = $this->db->update(
            "UPDATE orders SET status = 'pending', is_cancelled = 0, cancelled_at = NULL, cancellation_reason = NULL, updated_at = ?
              WHERE id = ? AND (status = 'cancelled' OR is_cancelled = 1)",
            [$now, $id]
        );

        return $affected > 0;
    }

    /** @param array<int, string> $reasons */
    public function recordFraudReview(int $id, float $score, array $reasons, bool $hold): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $reasonsJson = json_encode($reasons);

        if ($hold) {
            $this->db->update(
                'UPDATE orders SET fraud_score = ?, fraud_reasons = ?, status = ?, updated_at = ? WHERE id = ?',
                [$score, $reasonsJson, 'fraud', $now, $id]
            );

            return;
        }

        $this->db->update(
            'UPDATE orders SET fraud_score = ?, fraud_reasons = ?, updated_at = ? WHERE id = ?',
            [$score, $reasonsJson, $now, $id]
        );
    }

    public function stampFraudReviewer(int $id, int $adminId): void
    {
        $this->db->update(
            'UPDATE orders SET fraud_reviewed_by = ?, fraud_reviewed_at = ? WHERE id = ?',
            [$adminId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * order_items cascade-deletes with the order; services/invoices that
     * originated from this order keep existing independently (their
     * order_id FK is ON DELETE SET NULL) — deleting the order record
     * itself never deletes a client's service or invoice.
     */
    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM orders WHERE id = ?', [$id]);
    }

    /** Dashboard tile — a bare COUNT, not all()'s full joined row set (R17). */
    public function countPending(): int
    {
        $row = $this->db->selectOne("SELECT COUNT(*) AS c FROM orders WHERE status = 'pending'");

        return (int) ($row['c'] ?? 0);
    }
}
