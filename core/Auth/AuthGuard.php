<?php

declare(strict_types=1);

namespace CodeVault\Auth;

use CodeVault\Session\SessionManager;
use CodeVault\Staff\RoleRepository;

final class AuthGuard
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly AdminRepository $admins,
        private readonly RoleRepository $roles
    ) {
    }

    /** @return array<string, mixed>|null */
    public function currentAdmin(): ?array
    {
        $id = $this->session->get('admin_id');

        return $id === null ? null : $this->admins->findById((int) $id);
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        return $this->currentAdmin();
    }

    public function check(): bool
    {
        return $this->currentAdmin() !== null;
    }

    /** @param array<string, mixed> $admin */
    public function login(array $admin): void
    {
        $this->session->regenerate();
        $this->session->set('admin_id', $admin['id']);
    }

    public function logout(): void
    {
        $this->session->remove('admin_id');
        $this->session->regenerate();
    }

    /**
     * True if the current admin's role is a super-admin or has been
     * explicitly granted this permission key (PermissionRegistry).
     */
    public function can(string $permissionKey): bool
    {
        $admin = $this->currentAdmin();

        if ($admin === null || $admin['role_id'] === null) {
            return false;
        }

        $role = $this->roles->find((int) $admin['role_id']);

        if ($role === null) {
            return false;
        }

        if ((bool) $role['is_super_admin']) {
            return true;
        }

        return in_array($permissionKey, $this->roles->permissionsFor((int) $role['id']), true);
    }
}
