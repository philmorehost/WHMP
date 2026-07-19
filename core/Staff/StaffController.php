<?php

declare(strict_types=1);

namespace CodeVault\Staff;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * Staff Management (blueprint §4.3): admin accounts + role assignment.
 * Every action requires the `staff.manage` permission.
 */
final class StaffController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly AdminRepository $admins,
        private readonly RoleRepository $roles,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('staff.index', ['admins' => $this->admins->all()]);
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('staff.form', ['admin' => null, 'roles' => $this->roles->all(), 'error' => null]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $username = trim((string) $request->input('username', ''));
        $email = trim((string) $request->input('email', ''));
        $displayName = trim((string) $request->input('display_name', ''));
        $password = (string) $request->input('password', '');
        $roleId = $request->input('role_id') !== null && $request->input('role_id') !== ''
            ? (int) $request->input('role_id')
            : null;

        if ($username === '' || $email === '' || $displayName === '' || strlen($password) < 8) {
            return $this->render('staff.form', [
                'admin' => null,
                'roles' => $this->roles->all(),
                'error' => 'Username, email, display name are required and password must be at least 8 characters.',
            ]);
        }

        $id = $this->admins->create($username, $email, $password, $displayName, $roleId);
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'staff.created', 'admin', $id, "Created staff account {$username}", $request->ip());

        return Response::redirect('/admin/staff');
    }

    public function editForm(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $admin = $this->admins->findById((int) $params['id']);

        if ($admin === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('staff.form', ['admin' => $admin, 'roles' => $this->roles->all(), 'error' => null]);
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $email = trim((string) $request->input('email', ''));
        $displayName = trim((string) $request->input('display_name', ''));
        $roleId = $request->input('role_id') !== null && $request->input('role_id') !== ''
            ? (int) $request->input('role_id')
            : null;
        $password = (string) $request->input('password', '');

        $this->admins->updateProfile($id, $email, $displayName, $roleId);

        if ($password !== '') {
            $this->admins->updatePassword($id, $password);
        }

        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'staff.updated', 'admin', $id, "Updated staff account #{$id}", $request->ip());

        return Response::redirect('/admin/staff');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $currentAdmin = $this->guard->currentAdmin();

        if ($currentAdmin !== null && (int) $currentAdmin['id'] === $id) {
            return $this->render('staff.index', [
                'admins' => $this->admins->all(),
                'error' => 'You cannot delete your own account.',
            ]);
        }

        $this->admins->delete($id);
        $this->activity->log('admin', (int) $currentAdmin['id'], 'staff.deleted', 'admin', $id, "Deleted staff account #{$id}", $request->ip());

        return Response::redirect('/admin/staff');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::STAFF_MANAGE)) {
            return Response::html('403 Forbidden — missing staff.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Staff',
            'content' => $content,
        ]));
    }
}
