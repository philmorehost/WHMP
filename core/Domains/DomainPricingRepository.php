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
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->findByTld($tld);

        if ($existing !== null) {
            $this->db->update(
                'UPDATE domain_pricing SET registrar_slug = ?, register_price = ?, transfer_price = ?, renew_price = ?, updated_at = ? WHERE tld = ?',
                [$registrarSlug, $registerPrice, $transferPrice, $renewPrice, $now, $tld]
            );
        } else {
            $this->db->insert(
                'INSERT INTO domain_pricing (tld, registrar_slug, register_price, transfer_price, renew_price, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$tld, $registrarSlug, $registerPrice, $transferPrice, $renewPrice, $now, $now]
            );
        }
    }

    public function delete(int $id): void
    {
        $this->db->update('DELETE FROM domain_pricing WHERE id = ?', [$id]);
    }
}
