<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Database;
use DateTimeImmutable;

final class ConfigurableOptionRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function forGroup(int $groupId): array
    {
        return $this->db->select('SELECT * FROM configurable_options WHERE option_group_id = ? ORDER BY sort_order, name', [$groupId]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM configurable_options WHERE id = ?', [$id]);
    }

    public function create(int $groupId, string $name): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO configurable_options (option_group_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$groupId, $name, $now, $now]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM configurable_options WHERE id = ?', [$id]);
    }
}
