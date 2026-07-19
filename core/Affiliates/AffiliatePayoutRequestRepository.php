<?php

declare(strict_types=1);

namespace CodeVault\Affiliates;

use CodeVault\Database;
use DateTimeImmutable;

final class AffiliatePayoutRequestRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM affiliate_payout_requests WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forAffiliate(int $affiliateId): array
    {
        return $this->db->select('SELECT * FROM affiliate_payout_requests WHERE affiliate_id = ? ORDER BY id DESC', [$affiliateId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(?string $status = null): array
    {
        $where = $status !== null ? 'WHERE p.status = ?' : '';
        $bindings = $status !== null ? [$status] : [];

        return $this->db->select(
            <<<SQL
            SELECT p.*, a.code, c.first_name, c.last_name, c.email
            FROM affiliate_payout_requests p
            JOIN affiliates a ON a.id = p.affiliate_id
            JOIN clients c ON c.id = a.client_id
            {$where}
            ORDER BY p.id DESC
            SQL,
            $bindings
        );
    }

    public function hasOutstanding(int $affiliateId): bool
    {
        return $this->db->selectOne(
            "SELECT id FROM affiliate_payout_requests WHERE affiliate_id = ? AND status = 'requested'",
            [$affiliateId]
        ) !== null;
    }

    public function create(int $affiliateId, float $amount): int
    {
        return (int) $this->db->insert(
            'INSERT INTO affiliate_payout_requests (affiliate_id, amount, status, requested_at) VALUES (?, ?, ?, ?)',
            [$affiliateId, $amount, 'requested', (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->db->update(
            'UPDATE affiliate_payout_requests SET status = ?, processed_at = ? WHERE id = ?',
            [$status, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }
}
