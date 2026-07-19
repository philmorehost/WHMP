<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Database;
use DateTimeImmutable;

final class ClientGroupRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM client_groups ORDER BY name');
    }

    public function create(string $name, float $discountPercent = 0.0): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO client_groups (name, discount_percent, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, $discountPercent, $now, $now]
        );
    }

    public function delete(int $id): bool
    {
        $inUse = $this->db->selectOne('SELECT id FROM clients WHERE client_group_id = ? LIMIT 1', [$id]);

        if ($inUse !== null) {
            return false;
        }

        $this->db->delete('DELETE FROM client_groups WHERE id = ?', [$id]);

        return true;
    }
}
