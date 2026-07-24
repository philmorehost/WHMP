<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Database;
use DateTimeImmutable;

final class ProductRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(bool $includeHidden = true): array
    {
        $where = $includeHidden ? '' : "WHERE p.status = 'active'";

        return $this->db->select(
            "SELECT p.*, g.name AS group_name FROM products p JOIN product_groups g ON g.id = p.product_group_id {$where} ORDER BY p.sort_order, p.name"
        );
    }

    /**
     * All active products in one query, grouped by product_group_id in
     * PHP — avoids the N+1 of calling forGroup() once per group when
     * rendering the full store listing.
     *
     * @return array<int, array<int, array<string, mixed>>> product_group_id => products
     */
    public function allGroupedByGroup(): array
    {
        $rows = $this->db->select("SELECT * FROM products WHERE status = 'active' ORDER BY product_group_id, sort_order, name");
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['product_group_id']][] = $row;
        }

        return $grouped;
    }

    /** @return array<int, array<string, mixed>> */
    public function forGroup(int $groupId, bool $includeHidden = true): array
    {
        $where = $includeHidden ? '' : "AND status = 'active'";

        return $this->db->select(
            "SELECT * FROM products WHERE product_group_id = ? {$where} ORDER BY sort_order, name",
            [$groupId]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM products WHERE id = ?', [$id]);
    }

    /** Case-insensitive exact match — used by the service-import engine (R29) to resolve a CSV's plain-text product name to a real product_id. */
    public function findByName(string $name): ?array
    {
        return $this->db->selectOne('SELECT * FROM products WHERE LOWER(name) = LOWER(?)', [$name]);
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO products (product_group_id, server_group_id, whm_package_name, autosetup, name, description, status, type, pay_type, is_upsell, free_duration_type, free_duration_days, require_domain, upsell_pitch, stock_quantity, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['product_group_id'],
                $fields['server_group_id'] ?? null,
                $fields['whm_package_name'] ?? null,
                $fields['autosetup'] ?? 'payment',
                $fields['name'],
                $fields['description'] ?? null,
                $fields['status'] ?? 'active',
                $fields['type'] ?? 'other',
                $fields['pay_type'] ?? 'paid',
                !empty($fields['is_upsell']) ? 1 : 0,
                $fields['free_duration_type'] ?? 'lifetime',
                $fields['free_duration_days'] ?? null,
                !empty($fields['require_domain']) ? 1 : 0,
                $fields['upsell_pitch'] ?? null,
                $fields['stock_quantity'] ?? null,
                $fields['sort_order'] ?? 0,
                $now,
                $now,
            ]
        );
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $setClauses = [];
        $bindings = [];

        // Core columns that must exist (from earlier migrations, all pre-2024)
        $coreFields = [
            'product_group_id' => 'product_group_id',  // migration 0021
            'name' => 'name',                           // migration 0021
            'description' => 'description',             // migration 0021
            'status' => 'status',                       // migration 0021
            'stock_quantity' => 'stock_quantity',       // migration 0021
            'server_group_id' => 'server_group_id',    // migration 0043
            'autosetup' => 'autosetup',                 // migration 0111
            'type' => 'type',                           // migration 0102
            'is_upsell' => 'is_upsell',                 // migration 0064
            'require_domain' => 'require_domain',       // migration 0100
            'upsell_pitch' => 'upsell_pitch',           // migration 0064
        ];

        // Optional columns (from latest migrations, may not exist in older databases)
        $optionalFields = [
            'whm_package_name' => 'whm_package_name',  // migration 0120
            'pay_type' => 'pay_type',                   // migration 0120
            'free_duration_type' => 'free_duration_type', // migration 0120
            'free_duration_days' => 'free_duration_days', // migration 0120
        ];

        // Process core fields first
        foreach ($coreFields as $fieldKey => $columnName) {
            if (!isset($fields[$fieldKey])) {
                continue;
            }

            $setClauses[] = "{$columnName} = ?";

            if ($fieldKey === 'is_upsell' || $fieldKey === 'require_domain') {
                $bindings[] = !empty($fields[$fieldKey]) ? 1 : 0;
            } elseif ($fieldKey === 'autosetup') {
                $bindings[] = $fields[$fieldKey] ?? 'payment';
            } elseif ($fieldKey === 'status') {
                $bindings[] = $fields[$fieldKey] ?? 'active';
            } elseif ($fieldKey === 'type') {
                $bindings[] = $fields[$fieldKey] ?? 'other';
            } else {
                $bindings[] = $fields[$fieldKey] ?? null;
            }
        }

        // Process optional fields (may not exist in older databases)
        foreach ($optionalFields as $fieldKey => $columnName) {
            if (!isset($fields[$fieldKey])) {
                continue;
            }

            $setClauses[] = "{$columnName} = ?";
            $bindings[] = $fields[$fieldKey] ?? null;
        }

        if (empty($setClauses)) {
            return;
        }

        $setClauses[] = 'updated_at = ?';
        $bindings[] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $bindings[] = $id;

        $sql = 'UPDATE products SET ' . implode(', ', $setClauses) . ' WHERE id = ?';

        // Try to execute, but if a column doesn't exist, retry without optional fields
        try {
            $this->db->update($sql, $bindings);
        } catch (\Throwable $e) {
            // If the error is about a missing column, retry without optional fields
            if (strpos($e->getMessage(), 'Unknown column') !== false) {
                $setClauses = [];
                $bindings = [];

                // Only use core fields
                foreach ($coreFields as $fieldKey => $columnName) {
                    if (!isset($fields[$fieldKey])) {
                        continue;
                    }

                    $setClauses[] = "{$columnName} = ?";
                    $bindings[] = $fields[$fieldKey] ?? null;
                }

                if (!empty($setClauses)) {
                    $setClauses[] = 'updated_at = ?';
                    $bindings[] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
                    $bindings[] = $id;

                    $sql = 'UPDATE products SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
                    $this->db->update($sql, $bindings);
                }
            } else {
                throw $e;
            }
        }
    }

    public function bulkUpdate(array $productIds, array $fields): int
    {
        if (empty($productIds) || empty($fields)) {
            return 0;
        }

        $setClauses = [];
        $bindings = [];

        $fieldMap = [
            'server_group_id' => 'server_group_id',
            'require_domain' => 'require_domain',
        ];

        foreach ($fieldMap as $fieldKey => $columnName) {
            if (!isset($fields[$fieldKey])) {
                continue;
            }

            $setClauses[] = "{$columnName} = ?";

            if ($fieldKey === 'require_domain') {
                $bindings[] = !empty($fields[$fieldKey]) ? 1 : 0;
            } else {
                $bindings[] = $fields[$fieldKey] ?? null;
            }
        }

        if (empty($setClauses)) {
            return 0;
        }

        $setClauses[] = 'updated_at = ?';
        $bindings[] = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        foreach ($productIds as $id) {
            $bindings[] = $id;
        }

        $sql = 'UPDATE products SET ' . implode(', ', $setClauses) . " WHERE id IN ({$placeholders})";
        return $this->db->update($sql, $bindings);
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM products WHERE id = ?', [$id]);
    }

    /**
     * Active upsell offers (blueprint §4.4 "MarketConnect-style upsell"),
     * each paired with its cheapest billing cycle so a cart widget can
     * one-click add it without asking the shopper to pick a cycle first.
     *
     * @param array<int, int> $excludeProductIds products already in the cart
     * @return array<int, array<string, mixed>>
     */
    public function upsellProducts(array $excludeProductIds = []): array
    {
        $exclude = '';
        $bindings = [];

        if ($excludeProductIds !== []) {
            $placeholders = implode(',', array_fill(0, count($excludeProductIds), '?'));
            $exclude = "AND p.id NOT IN ({$placeholders})";
            $bindings = $excludeProductIds;
        }

        return $this->db->select(
            <<<SQL
            SELECT p.*, pp.billing_cycle AS upsell_cycle, pp.price AS upsell_price
            FROM products p
            JOIN product_pricing pp ON pp.product_id = p.id
                AND pp.price = (SELECT MIN(price) FROM product_pricing WHERE product_id = p.id)
            WHERE p.is_upsell = 1 AND p.status = 'active' {$exclude}
            GROUP BY p.id
            ORDER BY p.sort_order, p.name
            SQL,
            $bindings
        );
    }

    /**
     * Atomically decrements stock if available. Returns false (no row
     * touched) if the product is out of stock — the caller must treat that
     * as "cannot fulfill this order item" (blueprint §4.2 oversell protection).
     */
    public function decrementStock(int $id): bool
    {
        $affected = $this->db->update(
            'UPDATE products SET stock_quantity = stock_quantity - 1 WHERE id = ? AND stock_quantity IS NOT NULL AND stock_quantity > 0',
            [$id]
        );

        return $affected > 0;
    }

    public function hasUnlimitedOrAvailableStock(int $id): bool
    {
        $product = $this->find($id);

        return $product !== null && ($product['stock_quantity'] === null || (int) $product['stock_quantity'] > 0);
    }
}
