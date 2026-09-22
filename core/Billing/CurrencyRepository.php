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

    /**
     * The currency the CATALOG prices are entered in — the one the admin typed
     * into the product, add-on and domain pricing fields.
     *
     * Every "convert a catalog price" path in the app used to assume that was
     * the base/default currency (see CurrencyService::catalogRate()), which is
     * only true when prices happen to have been entered in the base currency.
     * On an install priced in naira with USD as the default, the raw figure was
     * multiplied by 1.0 and the ₦22,350 plan was quoted, ordered and reported
     * as $22,350.
     *
     * Falls back to the default currency, so an install that never sets the
     * flag behaves exactly as it did before.
     *
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        $row = $this->db->selectOne('SELECT * FROM currencies WHERE is_pricing = 1 LIMIT 1');

        return $row ?? $this->default();
    }

    /** Exactly one row can hold the pricing flag; marking $id clears it everywhere else. */
    public function setPricing(int $id): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->transaction(function () use ($id, $now) {
            $this->db->update('UPDATE currencies SET is_pricing = 0, updated_at = ?', [$now]);
            $this->db->update('UPDATE currencies SET is_pricing = 1, updated_at = ? WHERE id = ?', [$now, $id]);

            return null;
        });
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
            $outgoing = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE is_default = 1 LIMIT 1');
            $oldRate = $outgoing !== null ? (float) $outgoing['exchange_rate'] : 1.0;
            $oldRate = $oldRate > 0 ? $oldRate : 1.0;

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

            // ...and that reset makes every OTHER row's rate stale, which this
            // method used to leave behind: a rate reads "units of this currency
            // per 1 base unit", so it is meaningless once the base itself
            // changes. Promoting NGN kept USD at 1.0, so the two converted 1:1
            // and every price, invoice and gateway charge silently changed
            // meaning. Dividing by the outgoing base's rate restates them
            // against the new base — the old base lands on 1/1490 = 0.00067114,
            // which DECIMAL(18,8) holds (migration 0126 widened it for exactly
            // this kind of inverse rate).
            if (abs($oldRate - 1.0) > 0.00000001) {
                $this->db->update(
                    'UPDATE currencies SET exchange_rate = exchange_rate / ?, updated_at = ? WHERE id <> ?',
                    [$oldRate, $now, $id]
                );
            }

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
