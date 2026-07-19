<?php

declare(strict_types=1);

namespace CodeVault\Staff;

use CodeVault\Database;
use DateTimeImmutable;

final class RoleRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM roles ORDER BY name');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM roles WHERE id = ?', [$id]);
    }

    /** @return array<int, string> permission keys granted to this role */
    public function permissionsFor(int $roleId): array
    {
        return array_column(
            $this->db->select('SELECT permission_key FROM role_permissions WHERE role_id = ?', [$roleId]),
            'permission_key'
        );
    }

    /** @param array<int, string> $permissionKeys */
    public function create(string $name, bool $isSuperAdmin, array $permissionKeys): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $id = (int) $this->db->insert(
            'INSERT INTO roles (name, is_super_admin, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, $isSuperAdmin ? 1 : 0, $now, $now]
        );

        $this->syncPermissions($id, $permissionKeys);

        return $id;
    }

    /** @param array<int, string> $permissionKeys */
    public function update(int $id, string $name, bool $isSuperAdmin, array $permissionKeys): void
    {
        $this->db->update(
            'UPDATE roles SET name = ?, is_super_admin = ?, updated_at = ? WHERE id = ?',
            [$name, $isSuperAdmin ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );

        $this->syncPermissions($id, $permissionKeys);
    }

    public function delete(int $id): bool
    {
        $inUse = $this->db->selectOne('SELECT id FROM admins WHERE role_id = ? LIMIT 1', [$id]);

        if ($inUse !== null) {
            return false;
        }

        $this->db->delete('DELETE FROM roles WHERE id = ?', [$id]);

        return true;
    }

    /** @param array<int, string> $permissionKeys */
    private function syncPermissions(int $roleId, array $permissionKeys): void
    {
        $this->db->delete('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);

        $valid = array_intersect($permissionKeys, PermissionRegistry::keys());

        foreach ($valid as $key) {
            $this->db->insert('INSERT INTO role_permissions (role_id, permission_key) VALUES (?, ?)', [$roleId, $key]);
        }
    }
}
