<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ClientGroupController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ClientGroupRepository $groups
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('client-groups.index', ['groups' => $this->groups->all(), 'error' => null]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        $discount = (float) $request->input('discount_percent', 0);

        if ($name !== '') {
            $this->groups->create($name, $discount);
        }

        return Response::redirect('/admin/client-groups');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $deleted = $this->groups->delete((int) $params['id']);

        if (!$deleted) {
            return $this->render('client-groups.index', ['groups' => $this->groups->all(), 'error' => 'Cannot delete a group that still has clients assigned to it.']);
        }

        return Response::redirect('/admin/client-groups');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::CLIENTS_MANAGE)) {
            return Response::html('403 Forbidden — missing clients.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Client Groups',
            'content' => $content,
        ]));
    }
}
