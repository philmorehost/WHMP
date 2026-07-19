<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Staff\RoleRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

final class RoleRepositoryTest extends DatabaseTestCase
{
    private RoleRepository $roles;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->roles = new RoleRepository($this->db);
    }

    public function test_create_grants_only_the_given_permissions(): void
    {
        $id = $this->roles->create('Support Agent', false, [
            PermissionRegistry::CLIENTS_VIEW,
            PermissionRegistry::ACTIVITY_LOG_VIEW,
        ]);

        $granted = $this->roles->permissionsFor($id);

        $this->assertCount(2, $granted);
        $this->assertContains(PermissionRegistry::CLIENTS_VIEW, $granted);
        $this->assertNotContains(PermissionRegistry::STAFF_MANAGE, $granted);
    }

    public function test_create_ignores_unknown_permission_keys(): void
    {
        $id = $this->roles->create('Weird Role', false, ['not.a.real.permission', PermissionRegistry::CLIENTS_VIEW]);

        $this->assertSame([PermissionRegistry::CLIENTS_VIEW], $this->roles->permissionsFor($id));
    }

    public function test_update_replaces_the_permission_set(): void
    {
        $id = $this->roles->create('Evolving Role', false, [PermissionRegistry::CLIENTS_VIEW]);

        $this->roles->update($id, 'Evolving Role', false, [PermissionRegistry::STAFF_MANAGE]);

        $this->assertSame([PermissionRegistry::STAFF_MANAGE], $this->roles->permissionsFor($id));
    }

    public function test_delete_is_refused_while_a_role_is_assigned_to_an_admin(): void
    {
        $roleId = $this->roles->create('In Use', false, []);

        $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            ['staffer', 'staffer@example.test', 'x', 'Staffer', $roleId]
        );

        $this->assertFalse($this->roles->delete($roleId));

        $this->db->delete('DELETE FROM admins WHERE username = ?', ['staffer']);
        $this->assertTrue($this->roles->delete($roleId));
    }
}
