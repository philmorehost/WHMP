<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Database;
use DateTimeImmutable;

final class ProductGroupRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM product_groups ORDER BY sort_order, name');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM product_groups WHERE id = ?', [$id]);
    }

    /** Case-insensitive exact match — used by the product CSV importer to resolve a plain-text group name. */
    public function findByName(string $name): ?array
    {
        return $this->db->selectOne('SELECT * FROM product_groups WHERE LOWER(name) = LOWER(?)', [$name]);
    }

    public function create(string $name, ?string $description): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO product_groups (name, description, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, $description, $now, $now]
        );
    }

    public function update(int $id, string $name, ?string $description): void
    {
        $this->db->update(
            'UPDATE product_groups SET name = ?, description = ?, updated_at = ? WHERE id = ?',
            [$name, $description, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): bool
    {
        $inUse = $this->db->selectOne('SELECT id FROM products WHERE product_group_id = ? LIMIT 1', [$id]);

        if ($inUse !== null) {
            return false;
        }

        $this->db->delete('DELETE FROM product_groups WHERE id = ?', [$id]);

        return true;
    }
}
