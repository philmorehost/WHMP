<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ProductGroupController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ProductGroupRepository $groups,
        private readonly ProductRepository $products
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('catalog.groups-index', ['groups' => $this->groups->all(), 'error' => null]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', '')) ?: null;

        if ($name !== '') {
            $this->groups->create($name, $description);
        }

        return Response::redirect('/admin/products/groups');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $deleted = $this->groups->delete((int) $params['id']);

        if (!$deleted) {
            return $this->render('catalog.groups-index', ['groups' => $this->groups->all(), 'error' => 'Cannot delete a group that still has products in it.']);
        }

        return Response::redirect('/admin/products/groups');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::PRODUCTS_MANAGE)) {
            return Response::html('403 Forbidden — missing products.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Product Groups',
            'content' => $content,
        ]));
    }
}
