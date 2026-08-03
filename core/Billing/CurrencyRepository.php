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

        if ($row === null) {
            $any = $this->db->selectOne('SELECT * FROM currencies LIMIT 1');
            if ($any !== null) {
                $this->db->statement('UPDATE currencies SET is_default = 1 WHERE id = ?', [$any['id']]);
                $row = $this->db->selectOne('SELECT * FROM currencies WHERE is_default = 1 LIMIT 1');
            } else {
                $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
                $this->db->insert(
                    "INSERT INTO currencies (code, symbol, exchange_rate, is_default, created_at, updated_at) VALUES ('USD', '$', 1.0000, 1, ?, ?)",
                    [$now, $now]
                );
                $row = $this->db->selectOne('SELECT * FROM currencies WHERE is_default = 1 LIMIT 1');
            }
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
            [strtoupper($code), $symbol, $this->rateToStore($id, $exchangeRate), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setDefault(int $id): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->transaction(function () use ($id, $now) {
            $this->db->update('UPDATE currencies SET is_default = 0, updated_at = ?', [$now]);

            // Promoting a currency to base also resets its rate to 1. Every
            // "convert from base" multiplication in the app reads this column,
            // so a base currency left at, say, NGN=1490 inflates prices,
            // invoices and gateway charges by 1490x — the defect behind
            // ₦7,501.50 being taken to Paystack as ₦11,177,235. A rate is
            // per 1 base unit, so the base's own rate can only ever be 1.
            $this->db->update(
                'UPDATE currencies SET is_default = 1, exchange_rate = 1.0000, updated_at = ? WHERE id = ?',
                [$now, $id]
            );

            return null;
        });
    }

    /** The base currency's rate is 1 by definition; an edit cannot set it to anything else. */
    private function rateToStore(int $id, float $requestedRate): float
    {
        $row = $this->db->selectOne('SELECT is_default FROM currencies WHERE id = ?', [$id]);

        return ($row !== null && (int) $row['is_default'] === 1) ? 1.0000 : $requestedRate;
    }

    public function delete(int $id): bool
    {
        return $this->db->delete('DELETE FROM currencies WHERE id = ? AND is_default = 0', [$id]) > 0;
    }
}
