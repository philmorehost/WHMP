<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Promo popups — TENANT-SCOPED (migration 0204).
 *
 * `reseller_id IS NULL` is a platform banner and is only ever shown on the
 * platform's own site; `reseller_id = N` belongs to store N and is only ever
 * shown on that store's site. There is no fallback in either direction: a store
 * with no banner of its own shows NO banner, never the platform's. Every read
 * below takes the scope explicitly, and a null $resellerId always means "the
 * platform", never "any site".
 */
final class PromoBannerRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * The banners of one site: the platform's when $resellerId is null.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(?int $resellerId = null): array
    {
        [$scopeSql, $scopeBindings] = self::scope($resellerId);

        return $this->db->select(
            'SELECT * FROM promo_banners WHERE ' . $scopeSql . ' ORDER BY created_at DESC',
            $scopeBindings
        );
    }

    /**
     * One banner, only if it belongs to that site (the platform when null). The
     * ownership check is in the query, so an id from a URL is never trusted alone.
     *
     * @return array<string, mixed>|null
     */
    public function findScoped(int $id, ?int $resellerId): ?array
    {
        [$scopeSql, $scopeBindings] = self::scope($resellerId);

        return $this->db->selectOne(
            'SELECT * FROM promo_banners WHERE id = ? AND ' . $scopeSql . ' LIMIT 1',
            array_merge([$id], $scopeBindings)
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM promo_banners WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields, ?int $resellerId = null): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO promo_banners
                (reseller_id, name, template, eyebrow_text, headline, subtext, coupon_code, cta_text, target_pages, status, starts_at, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $resellerId,
                $fields['name'],
                $fields['template'],
                $fields['eyebrow_text'],
                $fields['headline'],
                $fields['subtext'],
                $fields['coupon_code'],
                $fields['cta_text'],
                $fields['target_pages'],
                $fields['status'],
                $fields['starts_at'],
                $fields['expires_at'],
                $now,
                $now,
            ]
        );
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields, ?int $resellerId = null): void
    {
        [$scopeSql, $scopeBindings] = self::scope($resellerId);

        $this->db->update(
            'UPDATE promo_banners SET
                name = ?, template = ?, eyebrow_text = ?, headline = ?, subtext = ?,
                coupon_code = ?, cta_text = ?, target_pages = ?, starts_at = ?, expires_at = ?, updated_at = ?
             WHERE id = ? AND ' . $scopeSql,
            array_merge([
                $fields['name'],
                $fields['template'],
                $fields['eyebrow_text'],
                $fields['headline'],
                $fields['subtext'],
                $fields['coupon_code'],
                $fields['cta_text'],
                $fields['target_pages'],
                $fields['starts_at'],
                $fields['expires_at'],
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ], $scopeBindings)
        );
    }

    /** @return bool whether a row was actually updated */
    public function setStatus(int $id, string $status, ?int $resellerId = null): bool
    {
        [$scopeSql, $scopeBindings] = self::scope($resellerId);

        return $this->db->update(
            'UPDATE promo_banners SET status = ?, updated_at = ? WHERE id = ? AND ' . $scopeSql,
            array_merge([
                $status,
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ], $scopeBindings)
        ) > 0;
    }

    public function delete(int $id, ?int $resellerId = null): bool
    {
        [$scopeSql, $scopeBindings] = self::scope($resellerId);

        return $this->db->delete(
            'DELETE FROM promo_banners WHERE id = ? AND ' . $scopeSql,
            array_merge([$id], $scopeBindings)
        ) > 0;
    }

    /**
     * The single most relevant active banner for a page ON ONE SITE, or null.
     * Only one is ever shown at a time — stacking popups is exactly the kind of
     * thing this feature exists to look better than.
     *
     * $resellerId is the site being served (null = the platform). This is the
     * line that keeps the platform's popup off every reseller's storefront.
     *
     * @return array<string, mixed>|null
     */
    public function activeForPage(string $pageKey, ?int $resellerId = null): ?array
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');
        [$scopeSql, $scopeBindings] = self::scope($resellerId);

        $candidates = $this->db->select(
            "SELECT * FROM promo_banners
             WHERE status = 'active'
               AND {$scopeSql}
               AND (starts_at IS NULL OR starts_at <= ?)
               AND (expires_at IS NULL OR expires_at >= ?)
             ORDER BY created_at DESC",
            array_merge($scopeBindings, [$today, $today])
        );

        foreach ($candidates as $banner) {
            $pages = json_decode((string) $banner['target_pages'], true);

            if (!is_array($pages)) {
                continue;
            }

            if (in_array(PromoBannerPages::ALL, $pages, true) || in_array($pageKey, $pages, true)) {
                return $banner;
            }
        }

        return null;
    }

    public function incrementImpressions(int $id): void
    {
        $this->db->update('UPDATE promo_banners SET impressions = impressions + 1 WHERE id = ?', [$id]);
    }

    public function incrementClicks(int $id): void
    {
        $this->db->update('UPDATE promo_banners SET clicks = clicks + 1 WHERE id = ?', [$id]);
    }

    /** @return array{0: string, 1: array<int, int>} */
    private static function scope(?int $resellerId): array
    {
        return $resellerId === null
            ? ['reseller_id IS NULL', []]
            : ['reseller_id = ?', [$resellerId]];
    }
}
