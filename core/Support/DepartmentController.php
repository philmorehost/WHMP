<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class DepartmentController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly DepartmentRepository $departments
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('support.departments-index', ['departments' => $this->departments->all()]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Departments',
            'content' => $content,
        ]));
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', '')) ?: null;

        if ($name !== '') {
            $this->departments->create($name, $email);
        }

        return Response::redirect('/admin/departments');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->departments->delete((int) $params['id']);

        return Response::redirect('/admin/departments');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::TICKETS_MANAGE)) {
            return Response::html('403 Forbidden — missing tickets.manage permission', 403);
        }

        return null;
    }
}
