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
        return $this->db->select('SELECT * FROM domain_pricing ORDER BY tld ASC');
    }

    /**
     * Every TLD grouped by its category, for the tabbed browse-by-category
     * UI on the public register page. Preserves category insertion order
     * (Popular first is the natural default) and TLD order within a group.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function allByCategory(): array
    {
        $grouped = [];

        foreach ($this->db->select('SELECT * FROM domain_pricing ORDER BY category ASC, tld ASC') as $row) {
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

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->findByTld($tld);

        if ($existing !== null) {
            $this->db->update(
                'UPDATE domain_pricing SET registrar_slug = ?, register_price = ?, transfer_price = ?, renew_price = ?, spinner_enabled = ?, category = ?, autosetup_registration = ?, autosetup_transfer = ?, updated_at = ? WHERE tld = ?',
                [$registrarSlug, $registerPrice, $transferPrice, $renewPrice, $spinnerEnabled, $category, $autosetupRegistration, $autosetupTransfer, $now, $tld]
            );
        } else {
            $this->db->insert(
                'INSERT INTO domain_pricing (tld, category, registrar_slug, register_price, transfer_price, renew_price, spinner_enabled, autosetup_registration, autosetup_transfer, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tld, $category, $registrarSlug, $registerPrice, $transferPrice, $renewPrice, $spinnerEnabled, $autosetupRegistration, $autosetupTransfer, $now, $now]
            );
        }
    }

    /** @return array<int, array<string, mixed>> TLDs the admin has opted into the Domain Spinner */
    public function spinnerEnabled(): array
    {
        return $this->db->select('SELECT * FROM domain_pricing WHERE spinner_enabled = 1 ORDER BY tld ASC');
    }

    public function delete(int $id): void
    {
        $this->db->update('DELETE FROM domain_pricing WHERE id = ?', [$id]);
    }
}
