<?php

declare(strict_types=1);

namespace CodeVault\Staff;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

final class RoleController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly RoleRepository $roles,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $roles = array_map(function (array $role) {
            $role['permissions'] = $this->roles->permissionsFor((int) $role['id']);

            return $role;
        }, $this->roles->all());

        return $this->render('staff.roles-index', ['roles' => $roles]);
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('staff.role-form', ['role' => null, 'grantedPermissions' => [], 'permissions' => PermissionRegistry::all()]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        $isSuperAdmin = (bool) $request->input('is_super_admin', false);
        $permissions = (array) $request->input('permissions', []);

        if ($name === '') {
            return $this->render('staff.role-form', [
                'role' => null,
                'grantedPermissions' => $permissions,
                'permissions' => PermissionRegistry::all(),
                'error' => 'Role name is required.',
            ]);
        }

        $id = $this->roles->create($name, $isSuperAdmin, $permissions);
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'role.created', 'role', $id, "Created role {$name}", $request->ip());

        return Response::redirect('/admin/roles');
    }

    public function editForm(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $role = $this->roles->find((int) $params['id']);

        if ($role === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('staff.role-form', [
            'role' => $role,
            'grantedPermissions' => $this->roles->permissionsFor((int) $role['id']),
            'permissions' => PermissionRegistry::all(),
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $name = trim((string) $request->input('name', ''));
        $isSuperAdmin = (bool) $request->input('is_super_admin', false);
        $permissions = (array) $request->input('permissions', []);

        $this->roles->update($id, $name, $isSuperAdmin, $permissions);
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'role.updated', 'role', $id, "Updated role #{$id}", $request->ip());

        return Response::redirect('/admin/roles');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $deleted = $this->roles->delete($id);

        if (!$deleted) {
            $roles = array_map(function (array $role) {
                $role['permissions'] = $this->roles->permissionsFor((int) $role['id']);

                return $role;
            }, $this->roles->all());

            return $this->render('staff.roles-index', ['roles' => $roles, 'error' => 'Cannot delete a role that still has staff assigned to it.']);
        }

        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'role.deleted', 'role', $id, "Deleted role #{$id}", $request->ip());

        return Response::redirect('/admin/roles');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::ROLES_MANAGE)) {
            return Response::html('403 Forbidden — missing roles.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Roles',
            'content' => $content,
        ]));
    }
}
