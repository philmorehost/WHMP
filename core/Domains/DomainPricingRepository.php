<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Database;
use DateTimeImmutable;

final class DomainPricingRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM domain_pricing ORDER BY sort_order ASC, tld ASC');
    }

    /**
     * Every TLD grouped by its category, for the tabbed browse-by-category
     * UI on the public register page. Preserves category insertion order
     * (Popular first is the natural default) and the admin-set sort_order
     * within a group (falling back to alphabetical for any TLD that hasn't
     * been explicitly ordered).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function allByCategory(): array
    {
        $grouped = [];

        foreach ($this->db->select('SELECT * FROM domain_pricing ORDER BY category ASC, sort_order ASC, tld ASC') as $row) {
            $category = trim((string) ($row['category'] ?? '')) ?: 'Other';
            $grouped[$category][] = $row;
        }

        // Surface "Popular" first when present — it's the default landing tab.
        if (isset($grouped['Popular'])) {
            $grouped = ['Popular' => $grouped['Popular']] + $grouped;
        }

        return $grouped;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM domain_pricing WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByTld(string $tld): ?array
    {
        $tld = '.' . ltrim(strtolower(trim($tld)), '.');
        return $this->db->selectOne('SELECT * FROM domain_pricing WHERE tld = ?', [$tld]);
    }

    /** @param array<string, mixed> $fields */
    public function save(array $fields): void
    {
        $tld = '.' . ltrim(strtolower(trim($fields['tld'])), '.');
        $registrarSlug = trim($fields['registrar_slug']);
        $registerPrice = (float) $fields['register_price'];
        $transferPrice = (float) $fields['transfer_price'];
        $renewPrice = (float) $fields['renew_price'];
        $spinnerEnabled = !empty($fields['spinner_enabled']) ? 1 : 0;
        $category = trim((string) ($fields['category'] ?? '')) ?: 'Popular';
        $autosetupRegistration = (string) ($fields['autosetup_registration'] ?? 'payment');
        $autosetupTransfer = (string) ($fields['autosetup_transfer'] ?? 'payment');

        if (!in_array($autosetupRegistration, ['order', 'payment', 'on_accept', 'off'], true)) {
            $autosetupRegistration = 'payment';
        }
        if (!in_array($autosetupTransfer, ['order', 'payment', 'on_accept', 'off'], true)) {
            $autosetupTransfer = 'payment';
        }

        $gracePeriodDays = (int) ($fields['grace_period_days'] ?? 30);
        $redemptionPeriodDays = (int) ($fields['redemption_period_days'] ?? 30);
        $redemptionFee = (float) ($fields['redemption_fee'] ?? 0.0);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->findByTld($tld);

        if ($existing !== null) {
            $this->db->update(
                'UPDATE domain_pricing SET registrar_slug = ?, register_price = ?, transfer_price = ?, renew_price = ?, grace_period_days = ?, redemption_period_days = ?, redemption_fee = ?, spinner_enabled = ?, category = ?, autosetup_registration = ?, autosetup_transfer = ?, updated_at = ? WHERE tld = ?',
                [$registrarSlug, $registerPrice, $transferPrice, $renewPrice, $gracePeriodDays, $redemptionPeriodDays, $redemptionFee, $spinnerEnabled, $category, $autosetupRegistration, $autosetupTransfer, $now, $tld]
            );
        } else {
            $this->db->insert(
                'INSERT INTO domain_pricing (tld, category, registrar_slug, register_price, transfer_price, renew_price, grace_period_days, redemption_period_days, redemption_fee, spinner_enabled, autosetup_registration, autosetup_transfer, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tld, $category, $registrarSlug, $registerPrice, $transferPrice, $renewPrice, $gracePeriodDays, $redemptionPeriodDays, $redemptionFee, $spinnerEnabled, $autosetupRegistration, $autosetupTransfer, $now, $now]
            );
        }
    }

    /** @return array<int, array<string, mixed>> TLDs the admin has opted into the Domain Spinner */
    public function spinnerEnabled(): array
    {
        return $this->db->select('SELECT * FROM domain_pricing WHERE spinner_enabled = 1 ORDER BY tld ASC');
    }

    /**
     * Applies the same set of changes to many TLDs at once.
     *
     * Deliberately a *partial* update: only the columns present in $fields are
     * written, so an admin can repoint 40 TLDs at a new registrar without
     * touching their individual prices. save() can't express that — it upserts
     * a whole row, so anything omitted would be reset to a default.
     *
     * @param array<int, int> $ids
     * @param array<string, mixed> $fields subset of the editable columns
     * @return int rows updated
     */
    public function bulkUpdate(array $ids, array $fields): int
    {
        // Whitelist: the column list is interpolated into SQL, so it can never
        // come from request keys directly.
        $allowed = ['category', 'registrar_slug', 'register_price', 'transfer_price', 'renew_price', 'spinner_enabled', 'grace_period_days', 'redemption_period_days', 'redemption_fee'];

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $sets = [];
        $bindings = [];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }

            $sets[] = "{$column} = ?";
            $bindings[] = $fields[$column];
        }

        if ($ids === [] || $sets === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $bindings = array_merge($bindings, $ids);

        return $this->db->update(
            'UPDATE domain_pricing SET ' . implode(', ', $sets) . " WHERE id IN ({$placeholders})",
            $bindings
        );
    }

    public function delete(int $id): void
    {
        $this->db->update('DELETE FROM domain_pricing WHERE id = ?', [$id]);
    }

    /**
     * Applies a new sort_order to many TLDs at once, from the admin's
     * per-row "Order" numbers on the pricing table.
     *
     * @param array<int, int> $sortOrders TLD id => new sort_order
     * @return int rows updated
     */
    public function reorder(array $sortOrders): int
    {
        $updated = 0;

        foreach ($sortOrders as $id => $order) {
            $updated += $this->db->update(
                'UPDATE domain_pricing SET sort_order = ? WHERE id = ?',
                [max(0, (int) $order), (int) $id]
            );
        }

        return $updated;
    }
}
