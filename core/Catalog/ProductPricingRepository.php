<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Database;

final class ProductPricingRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, array<string, mixed>> billing_cycle => pricing row */
    public function forProduct(int $productId): array
    {
        $rows = $this->db->select('SELECT * FROM product_pricing WHERE product_id = ?', [$productId]);
        $byCycle = [];

        foreach ($rows as $row) {
            $byCycle[$row['billing_cycle']] = $row;
        }

        return $byCycle;
    }

    /** @return array<string, mixed>|null */
    public function find(int $productId, string $cycle): ?array
    {
        return $this->db->selectOne('SELECT * FROM product_pricing WHERE product_id = ? AND billing_cycle = ?', [$productId, $cycle]);
    }

    public function setPricing(int $productId, string $cycle, float $setupFee, float $price): void
    {
        $existing = $this->find($productId, $cycle);

        if ($existing === null) {
            $this->db->insert(
                'INSERT INTO product_pricing (product_id, billing_cycle, setup_fee, price) VALUES (?, ?, ?, ?)',
                [$productId, $cycle, $setupFee, $price]
            );

            return;
        }

        $this->db->update(
            'UPDATE product_pricing SET setup_fee = ?, price = ? WHERE product_id = ? AND billing_cycle = ?',
            [$setupFee, $price, $productId, $cycle]
        );
    }

    public function removePricing(int $productId, string $cycle): void
    {
        $this->db->delete('DELETE FROM product_pricing WHERE product_id = ? AND billing_cycle = ?', [$productId, $cycle]);
    }
}
