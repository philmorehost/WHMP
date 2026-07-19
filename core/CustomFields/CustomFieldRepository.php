<?php

declare(strict_types=1);

namespace CodeVault\CustomFields;

use CodeVault\Database;
use DateTimeImmutable;

final class CustomFieldRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function forType(string $fieldFor = 'client'): array
    {
        return $this->db->select('SELECT * FROM custom_fields WHERE field_for = ? ORDER BY sort_order, name', [$fieldFor]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM custom_fields WHERE id = ?', [$id]);
    }

    public function create(string $fieldFor, string $name, string $type, ?string $options, bool $required, bool $adminOnly): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO custom_fields (field_for, name, type, options, required, admin_only, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$fieldFor, $name, $type, $options, $required ? 1 : 0, $adminOnly ? 1 : 0, $now, $now]
        );
    }

    public function update(int $id, string $name, string $type, ?string $options, bool $required, bool $adminOnly): void
    {
        $this->db->update(
            'UPDATE custom_fields SET name = ?, type = ?, options = ?, required = ?, admin_only = ?, updated_at = ? WHERE id = ?',
            [$name, $type, $options, $required ? 1 : 0, $adminOnly ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM custom_fields WHERE id = ?', [$id]);
    }
}
