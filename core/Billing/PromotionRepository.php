<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Promo/coupon codes (blueprint §4.2). Codes are unique and matched
 * case-insensitively — "SUMMER20" and "summer20" are the same code, since
 * that's how a shopper is likely to type it.
 *
 * TENANT-SCOPED (migration 0204). Every code belongs to exactly one site:
 * `reseller_id IS NULL` is the platform's own code, `reseller_id = N` belongs
 * to store N. Every lookup takes the scope and matches it EXACTLY — a platform
 * code is never valid on a store and a store's code is never valid anywhere
 * else — so a null $resellerId below always means "the platform", never "any".
 */
final class PromotionRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * The codes of one site: the platform's when $resellerId is null.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(?int $resellerId = null): array
    {
        [$scopeSql, $scopeBindings] = self::scope($resellerId);

        return $this->db->select('SELECT * FROM promotions WHERE ' . $scopeSql . ' ORDER BY code', $scopeBindings);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM promotions WHERE id = ?', [$id]);
    }

    /**
     * One code on one site. The platform's site when $resellerId is null.
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code, ?int $resellerId = null): ?array
    {
        [$scopeSql, $scopeBindings] = self::scope($resellerId);

        return $this->db->selectOne(
            'SELECT * FROM promotions WHERE UPPER(code) = UPPER(?) AND ' . $scopeSql . ' LIMIT 1',
            array_merge([trim($code)], $scopeBindings)
        );
    }

    /**
     * One promotion, only if it belongs to that store. The ownership check is
     * part of the query, so an id taken from a URL is never trusted on its own.
     *
     * @return array<string, mixed>|null
     */
    public function findForReseller(int $id, int $resellerId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM promotions WHERE id = ? AND reseller_id = ? LIMIT 1',
            [$id, $resellerId]
        );
    }

    /**
     * Upsert keyed by code — matches TaxRuleRepository::setRate()'s
     * single-page "add / update" admin UI convention.
     *
     * Scoped like every other lookup: saving "SAVE10" for a store can only
     * ever create or update THAT store's SAVE10.
     *
     * @param array<string, mixed> $fields
     */
    public function save(array $fields, ?int $resellerId = null): void
    {
        $code = strtoupper(trim((string) $fields['code']));
        $existing = $this->findByCode($code, $resellerId);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $columns = [
            'type' => $fields['type'],
            'value' => (float) $fields['value'],
            'max_redemptions' => $fields['max_redemptions'] ?? null,
            'min_order_amount' => (float) ($fields['min_order_amount'] ?? 0),
            'starts_at' => $fields['starts_at'] ?? null,
            'expires_at' => $fields['expires_at'] ?? null,
            'status' => $fields['status'] ?? 'active',
        ];

        if ($existing === null) {
            $this->db->insert(
                'INSERT INTO promotions (reseller_id, code, type, value, max_redemptions, min_order_amount, starts_at, expires_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $resellerId,
                    $code,
                    $columns['type'],
                    $columns['value'],
                    $columns['max_redemptions'],
                    $columns['min_order_amount'],
                    $columns['starts_at'],
                    $columns['expires_at'],
                    $columns['status'],
                    $now,
                    $now,
                ]
            );

            return;
        }

        $this->db->update(
            'UPDATE promotions SET type = ?, value = ?, max_redemptions = ?, min_order_amount = ?, starts_at = ?, expires_at = ?, status = ?, updated_at = ? WHERE id = ?',
            [
                $columns['type'],
                $columns['value'],
                $columns['max_redemptions'],
                $columns['min_order_amount'],
                $columns['starts_at'],
                $columns['expires_at'],
                $columns['status'],
                $now,
                $existing['id'],
            ]
        );
    }

    public function incrementRedemptions(int $id): void
    {
        $this->db->update(
            'UPDATE promotions SET redemption_count = redemption_count + 1, updated_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** Deletes one of the PLATFORM's codes. A store's code is never touched from here. */
    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM promotions WHERE id = ? AND reseller_id IS NULL', [$id]);
    }

    /** Deletes one of a store's codes, only if it is that store's. */
    public function deleteForReseller(int $id, int $resellerId): bool
    {
        return $this->db->delete('DELETE FROM promotions WHERE id = ? AND reseller_id = ?', [$id, $resellerId]) > 0;
    }

    /** @return array{0: string, 1: array<int, int>} */
    private static function scope(?int $resellerId): array
    {
        return $resellerId === null
            ? ['reseller_id IS NULL', []]
            : ['reseller_id = ?', [$resellerId]];
    }
}
