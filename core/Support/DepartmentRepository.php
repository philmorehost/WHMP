<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Database;
use DateTimeImmutable;

final class DepartmentRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM departments ORDER BY name');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM departments WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne('SELECT * FROM departments WHERE email = ?', [$email]);
    }

    public function create(string $name, ?string $email): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO departments (name, email, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, $email, $now, $now]
        );
    }

    public function update(int $id, string $name, ?string $email): void
    {
        $this->db->update(
            'UPDATE departments SET name = ?, email = ?, updated_at = ? WHERE id = ?',
            [$name, $email, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM departments WHERE id = ?', [$id]);
    }
}
