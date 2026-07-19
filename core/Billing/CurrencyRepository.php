<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

final class CurrencyRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM currencies ORDER BY is_default DESC, code ASC');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM currencies WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->db->selectOne('SELECT * FROM currencies WHERE code = ?', [strtoupper($code)]);
    }

    /** @return array<string, mixed> */
    public function default(): array
    {
        $row = $this->db->selectOne('SELECT * FROM currencies WHERE is_default = 1 LIMIT 1');

        // Every install seeds exactly one default currency (migration 0037)
        // and setDefault() never leaves zero defaults, so this is
        // unreachable outside a corrupted database — fail loudly rather
        // than silently pricing everything at a fabricated rate.
        if ($row === null) {
            throw new \RuntimeException('No default currency configured.');
        }

        return $row;
    }

    public function create(string $code, string $symbol, float $exchangeRate): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO currencies (code, symbol, exchange_rate, is_default, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)',
            [strtoupper($code), $symbol, $exchangeRate, $now, $now]
        );
    }

    public function update(int $id, string $code, string $symbol, float $exchangeRate): void
    {
        $this->db->update(
            'UPDATE currencies SET code = ?, symbol = ?, exchange_rate = ?, updated_at = ? WHERE id = ?',
            [strtoupper($code), $symbol, $exchangeRate, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setDefault(int $id): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->transaction(function () use ($id, $now) {
            $this->db->update('UPDATE currencies SET is_default = 0, updated_at = ?', [$now]);
            $this->db->update('UPDATE currencies SET is_default = 1, updated_at = ? WHERE id = ?', [$now, $id]);

            return null;
        });
    }

    public function delete(int $id): bool
    {
        return $this->db->delete('DELETE FROM currencies WHERE id = ? AND is_default = 0', [$id]) > 0;
    }
}
