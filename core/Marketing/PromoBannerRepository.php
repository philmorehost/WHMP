<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Database;
use DateTimeImmutable;

final class PromoBannerRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM promo_banners ORDER BY created_at DESC');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM promo_banners WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO promo_banners
                (name, template, eyebrow_text, headline, subtext, coupon_code, cta_text, target_pages, status, starts_at, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
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
    public function update(int $id, array $fields): void
    {
        $this->db->update(
            'UPDATE promo_banners SET
                name = ?, template = ?, eyebrow_text = ?, headline = ?, subtext = ?,
                coupon_code = ?, cta_text = ?, target_pages = ?, starts_at = ?, expires_at = ?, updated_at = ?
             WHERE id = ?',
            [
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
            ]
        );
    }

    /** @return bool whether a row was actually updated */
    public function setStatus(int $id, string $status): bool
    {
        return $this->db->update('UPDATE promo_banners SET status = ?, updated_at = ? WHERE id = ?', [
            $status,
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            $id,
        ]) > 0;
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM promo_banners WHERE id = ?', [$id]);
    }

    /**
     * The single most relevant active banner for a page, or null. Only one is
     * ever shown at a time — stacking popups is exactly the kind of thing this
     * feature exists to look better than.
     *
     * @return array<string, mixed>|null
     */
    public function activeForPage(string $pageKey): ?array
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');

        $candidates = $this->db->select(
            "SELECT * FROM promo_banners
             WHERE status = 'active'
               AND (starts_at IS NULL OR starts_at <= ?)
               AND (expires_at IS NULL OR expires_at >= ?)
             ORDER BY created_at DESC",
            [$today, $today]
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
}
