<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Promo/coupon codes (blueprint §4.2). Codes are unique and matched
 * case-insensitively — "SUMMER20" and "summer20" are the same code, since
 * that's how a shopper is likely to type it.
 */
final class PromotionRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM promotions ORDER BY code');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM promotions WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->db->selectOne('SELECT * FROM promotions WHERE UPPER(code) = UPPER(?)', [trim($code)]);
    }

    /**
     * Upsert keyed by code — matches TaxRuleRepository::setRate()'s
     * single-page "add / update" admin UI convention.
     *
     * @param array<string, mixed> $fields
     */
    public function save(array $fields): void
    {
        $code = strtoupper(trim((string) $fields['code']));
        $existing = $this->findByCode($code);
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
                'INSERT INTO promotions (code, type, value, max_redemptions, min_order_amount, starts_at, expires_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
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

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM promotions WHERE id = ?', [$id]);
    }
}
