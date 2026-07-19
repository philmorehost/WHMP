<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Staff\RoleRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

final class AuthGuardPermissionTest extends DatabaseTestCase
{
    private AuthGuard $guard;
    private RoleRepository $roles;
    private int $adminId;
    private string $emptyConfigDir;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->roles = new RoleRepository($this->db);
        $admins = new AdminRepository($this->db);

        // Directly manipulating $_SESSION (rather than SessionManager::start(),
        // which calls the real session_start()) keeps each test isolated
        // without PHP CLI session-lifecycle noise across test methods.
        // Config points at a bare temp dir (no .env) rather than the real
        // project root — loading the real .env here would putenv() process-wide
        // state that leaks into other tests for the rest of the PHPUnit run.
        $_SESSION = [];
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-authguard-test-' . uniqid();
        mkdir($this->emptyConfigDir);
        $session = new SessionManager(new Config($this->emptyConfigDir));

        $this->guard = new AuthGuard($session, $admins, $this->roles);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->emptyConfigDir);
        parent::tearDown();
    }

    private function createAdmin(?int $roleId): int
    {
        return (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            ['staffer', 'staffer@example.test', 'x', 'Staffer', $roleId]
        );
    }

    public function test_no_logged_in_admin_has_no_permissions(): void
    {
        $this->assertFalse($this->guard->can(PermissionRegistry::CLIENTS_VIEW));
        $this->assertFalse($this->guard->check());
    }

    public function test_super_admin_role_bypasses_the_permission_matrix(): void
    {
        $roleId = $this->roles->create('Owner', true, []);
        $adminId = $this->createAdmin($roleId);
        $_SESSION['admin_id'] = $adminId;

        $this->assertTrue($this->guard->can(PermissionRegistry::STAFF_MANAGE));
        $this->assertTrue($this->guard->can(PermissionRegistry::CLIENTS_MANAGE));
    }

    public function test_scoped_role_only_grants_its_explicit_permissions(): void
    {
        $roleId = $this->roles->create('Support Agent', false, [PermissionRegistry::CLIENTS_VIEW]);
        $adminId = $this->createAdmin($roleId);
        $_SESSION['admin_id'] = $adminId;

        $this->assertTrue($this->guard->can(PermissionRegistry::CLIENTS_VIEW));
        $this->assertFalse($this->guard->can(PermissionRegistry::CLIENTS_MANAGE));
        $this->assertFalse($this->guard->can(PermissionRegistry::STAFF_MANAGE));
    }

    public function test_admin_with_no_role_has_no_permissions(): void
    {
        $adminId = $this->createAdmin(null);
        $_SESSION['admin_id'] = $adminId;

        $this->assertTrue($this->guard->check());
        $this->assertFalse($this->guard->can(PermissionRegistry::CLIENTS_VIEW));
    }
}
