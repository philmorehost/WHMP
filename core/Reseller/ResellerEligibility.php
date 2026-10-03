<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;

/**
 * Who may become a reseller — and so who sees the Reseller area at all.
 *
 * TWO TIERS, NEVER THREE
 *
 *   tier 1  a platform customer (clients.reseller_id IS NULL). Buys at the platform's
 *           reseller discount.
 *   tier 2  a customer of a tier-1 store (a sub-reseller). Buys at that store's retail
 *           prices, so the tier-1 reseller keeps its profit (ResellerRetailPricing).
 *
 * A customer of a tier-2 store may not resell: the chain stops there. The Reseller
 * area is hidden from them and every /client/reseller URL refuses them (Kernel), so a
 * sub-reseller's store only ever has customers, never resellers of its own.
 */
final class ResellerEligibility
{
    public const MAX_TIER = 2;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The tier this client would resell at, or null when they may not resell.
     *
     * @param array<string, mixed> $client a clients row (needs reseller_id)
     */
    public function tierFor(array $client): ?int
    {
        $storeId = (int) ($client['reseller_id'] ?? 0);

        if ($storeId <= 0) {
            return 1;
        }

        $row = $this->db->selectOne(
            'SELECT o.reseller_id AS owner_store_id
               FROM resellers r JOIN clients o ON o.id = r.client_id
              WHERE r.id = ? LIMIT 1',
            [$storeId]
        );

        // The store they belonged to is gone: they are, in effect, a platform customer.
        if ($row === null) {
            return 1;
        }

        return self::tierFromOwnerStore($row['owner_store_id'] ?? null);
    }

    /** @param array<string, mixed> $client */
    public function canResell(array $client): bool
    {
        return $this->tierFor($client) !== null;
    }

    /**
     * The decision itself, from the one fact it rests on: whether the owner of the
     * client's store is themselves some store's customer.
     */
    public static function tierFromOwnerStore(mixed $ownerStoreId): ?int
    {
        return (int) ($ownerStoreId ?? 0) > 0 ? null : 2;
    }

    /** Paths that make up the Reseller area (and so are refused to an ineligible client). */
    public static function isResellerAreaPath(string $path): bool
    {
        return $path === '/client/reseller' || str_starts_with($path, '/client/reseller/');
    }
}
