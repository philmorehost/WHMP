<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Database;
use DateTimeImmutable;

final class ConfigurableOptionGroupRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM configurable_option_groups ORDER BY name');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM configurable_option_groups WHERE id = ?', [$id]);
    }

    public function create(string $name): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO configurable_option_groups (name, created_at, updated_at) VALUES (?, ?, ?)',
            [$name, $now, $now]
        );
    }

    public function update(int $id, string $name): void
    {
        $this->db->update(
            'UPDATE configurable_option_groups SET name = ?, updated_at = ? WHERE id = ?',
            [$name, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM configurable_option_groups WHERE id = ?', [$id]);
    }

    /** @return array<int, int> option_group_ids attached to a product */
    public function idsForProduct(int $productId): array
    {
        return array_map('intval', array_column(
            $this->db->select('SELECT option_group_id FROM product_configurable_option_groups WHERE product_id = ?', [$productId]),
            'option_group_id'
        ));
    }

    /** @return array<int, array<string, mixed>> option groups attached to a product */
    public function forProduct(int $productId): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT g.* FROM configurable_option_groups g
            INNER JOIN product_configurable_option_groups p ON p.option_group_id = g.id
            WHERE p.product_id = ?
            ORDER BY g.name
            SQL,
            [$productId]
        );
    }

    /** @param array<int, int> $groupIds */
    public function syncForProduct(int $productId, array $groupIds): void
    {
        $this->db->delete('DELETE FROM product_configurable_option_groups WHERE product_id = ?', [$productId]);

        foreach (array_unique($groupIds) as $groupId) {
            $this->db->insert(
                'INSERT INTO product_configurable_option_groups (product_id, option_group_id) VALUES (?, ?)',
                [$productId, $groupId]
            );
        }
    }
}
