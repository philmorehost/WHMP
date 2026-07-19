<?php

declare(strict_types=1);

namespace CodeVault\Affiliates;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Append-only commission ledger (same "balance is always SUM(), never a
 * stored column" idiom as ClientCreditRepository). `status` moves
 * pending -> requested -> paid as a payout request is made and approved.
 */
final class AffiliateCommissionRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function create(int $affiliateId, int $invoiceId, float $amount): int
    {
        return (int) $this->db->insert(
            'INSERT INTO affiliate_commissions (affiliate_id, invoice_id, amount, status, created_at) VALUES (?, ?, ?, ?, ?)',
            [$affiliateId, $invoiceId, $amount, 'pending', (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    public function existsForInvoice(int $affiliateId, int $invoiceId): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM affiliate_commissions WHERE affiliate_id = ? AND invoice_id = ?',
            [$affiliateId, $invoiceId]
        ) !== null;
    }

    public function pendingTotal(int $affiliateId): float
    {
        $row = $this->db->selectOne(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM affiliate_commissions WHERE affiliate_id = ? AND status = 'pending'",
            [$affiliateId]
        );

        return (float) ($row['total'] ?? 0);
    }

    public function lifetimeTotal(int $affiliateId): float
    {
        $row = $this->db->selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM affiliate_commissions WHERE affiliate_id = ?',
            [$affiliateId]
        );

        return (float) ($row['total'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public function forAffiliate(int $affiliateId): array
    {
        return $this->db->select('SELECT * FROM affiliate_commissions WHERE affiliate_id = ? ORDER BY id DESC', [$affiliateId]);
    }

    public function markRequested(int $affiliateId): void
    {
        $this->db->update(
            "UPDATE affiliate_commissions SET status = 'requested' WHERE affiliate_id = ? AND status = 'pending'",
            [$affiliateId]
        );
    }

    public function markPaidForAffiliate(int $affiliateId): void
    {
        $this->db->update(
            "UPDATE affiliate_commissions SET status = 'paid' WHERE affiliate_id = ? AND status = 'requested'",
            [$affiliateId]
        );
    }

    public function revertRequestedToPending(int $affiliateId): void
    {
        $this->db->update(
            "UPDATE affiliate_commissions SET status = 'pending' WHERE affiliate_id = ? AND status = 'requested'",
            [$affiliateId]
        );
    }
}
