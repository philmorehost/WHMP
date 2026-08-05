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
        $this->db->update(
            'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
            ['cancelled', (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
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
