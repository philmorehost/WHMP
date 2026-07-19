<?php

declare(strict_types=1);

namespace CodeVault\Downloads;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class DownloadCategoryController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly DownloadCategoryRepository $categories
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('downloads.categories-index', ['categories' => $this->categories->all(), 'error' => null]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        $sortOrder = (int) $request->input('sort_order', 0);

        if ($name !== '') {
            $this->categories->create($name, $sortOrder);
        }

        return Response::redirect('/admin/downloads/categories');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $deleted = $this->categories->delete((int) $params['id']);

        if (!$deleted) {
            return $this->render('downloads.categories-index', [
                'categories' => $this->categories->all(),
                'error' => 'Cannot delete a category that still has downloads in it.',
            ]);
        }

        return Response::redirect('/admin/downloads/categories');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::DOWNLOADS_MANAGE)) {
            return Response::html('403 Forbidden — missing downloads.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Download Categories',
            'content' => $content,
        ]));
    }
}
