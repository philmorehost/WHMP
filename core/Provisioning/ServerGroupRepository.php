<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Database;
use DateTimeImmutable;

final class ServerGroupRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM server_groups ORDER BY name');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM server_groups WHERE id = ?', [$id]);
    }

    public function create(string $name): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO server_groups (name, created_at, updated_at) VALUES (?, ?, ?)',
            [$name, $now, $now]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM server_groups WHERE id = ?', [$id]);
    }
}
