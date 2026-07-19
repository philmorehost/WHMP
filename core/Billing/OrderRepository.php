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

        return $this->db->select(
            <<<SQL
            SELECT o.*, c.email AS client_email, c.first_name, c.last_name
            FROM orders o
            JOIN clients c ON c.id = o.client_id
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
            SELECT o.*, c.email AS client_email, c.first_name, c.last_name
            FROM orders o
            JOIN clients c ON c.id = o.client_id
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

    /** Dashboard tile — a bare COUNT, not all()'s full joined row set (R17). */
    public function countPending(): int
    {
        $row = $this->db->selectOne("SELECT COUNT(*) AS c FROM orders WHERE status = 'pending'");

        return (int) ($row['c'] ?? 0);
    }
}
