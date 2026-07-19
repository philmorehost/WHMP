<?php

declare(strict_types=1);

namespace CodeVault\Affiliates;

use CodeVault\Database;
use DateTimeImmutable;

final class AffiliateReferralRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findByReferredClient(int $clientId): ?array
    {
        return $this->db->selectOne('SELECT * FROM affiliate_referrals WHERE referred_client_id = ?', [$clientId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forAffiliate(int $affiliateId): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT r.*, c.first_name, c.last_name, c.email
            FROM affiliate_referrals r
            JOIN clients c ON c.id = r.referred_client_id
            WHERE r.affiliate_id = ?
            ORDER BY r.id DESC
            SQL,
            [$affiliateId]
        );
    }

    public function create(int $affiliateId, int $referredClientId): int
    {
        return (int) $this->db->insert(
            'INSERT INTO affiliate_referrals (affiliate_id, referred_client_id, created_at) VALUES (?, ?, ?)',
            [$affiliateId, $referredClientId, (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }
}
