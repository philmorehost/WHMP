<?php

declare(strict_types=1);

namespace CodeVault\Affiliates;

use CodeVault\Database;
use DateTimeImmutable;

final class AffiliateRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            <<<'SQL'
            SELECT a.*, cu.symbol AS currency_symbol, cu.exchange_rate AS currency_rate
            FROM affiliates a
            JOIN clients c ON c.id = a.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            WHERE a.id = ?
            SQL,
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByClient(int $clientId): ?array
    {
        return $this->db->selectOne('SELECT * FROM affiliates WHERE client_id = ?', [$clientId]);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->db->selectOne('SELECT * FROM affiliates WHERE code = ?', [$code]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT a.*, c.first_name, c.last_name, c.email, cu.symbol AS currency_symbol, cu.exchange_rate AS currency_rate
            FROM affiliates a
            JOIN clients c ON c.id = a.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            ORDER BY a.id DESC
            SQL
        );
    }

    public function create(int $clientId, float $commissionRate = 10.00): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO affiliates (client_id, code, status, commission_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$clientId, $this->generateUniqueCode(), 'active', $commissionRate, $now, $now]
        );
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->update(
            'UPDATE affiliates SET status = ?, updated_at = ? WHERE id = ?',
            [$status, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setCommissionRate(int $id, float $rate): void
    {
        $this->db->update(
            'UPDATE affiliates SET commission_rate = ?, updated_at = ? WHERE id = ?',
            [$rate, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while ($this->findByCode($code) !== null);

        return $code;
    }
}
