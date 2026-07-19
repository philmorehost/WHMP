<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class KbCategoryController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly KbCategoryRepository $categories
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('knowledgebase.categories-index', ['categories' => $this->categories->all(), 'error' => null]);
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

        return Response::redirect('/admin/kb/categories');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $deleted = $this->categories->delete((int) $params['id']);

        if (!$deleted) {
            return $this->render('knowledgebase.categories-index', [
                'categories' => $this->categories->all(),
                'error' => 'Cannot delete a category that still has articles in it.',
            ]);
        }

        return Response::redirect('/admin/kb/categories');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::KB_MANAGE)) {
            return Response::html('403 Forbidden — missing kb.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — KB Categories',
            'content' => $content,
        ]));
    }
}
