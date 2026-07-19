<?php

declare(strict_types=1);

namespace CodeVault\Downloads;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class DownloadController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly DownloadRepository $downloads,
        private readonly DownloadCategoryRepository $categories,
        private readonly string $storagePath
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('downloads.admin-index', ['downloads' => $this->downloads->all()]);
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('downloads.admin-form', ['categories' => $this->categories->all(), 'error' => null]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $categoryId = (int) $request->input('category_id', 0);
        $name = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', '')) ?: null;
        $file = $request->file('file');

        if ($categoryId <= 0 || $name === '' || $file === null) {
            return $this->render('downloads.admin-form', [
                'categories' => $this->categories->all(),
                'error' => 'Category, name, and a file are required.',
            ]);
        }

        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $storedName = uniqid('dl_', true) . '_' . basename((string) $file['name']);
        $destination = $this->storagePath . '/' . $storedName;

        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            return $this->render('downloads.admin-form', [
                'categories' => $this->categories->all(),
                'error' => 'Failed to store the uploaded file.',
            ]);
        }

        $this->downloads->create($categoryId, $name, $description, $storedName, (int) $file['size']);

        return Response::redirect('/admin/downloads');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $download = $this->downloads->find($id);

        if ($download !== null) {
            $path = $this->storagePath . '/' . $download['file_path'];

            if (is_file($path)) {
                unlink($path);
            }

            $this->downloads->delete($id);
        }

        return Response::redirect('/admin/downloads');
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
            'title' => 'CodeVault Admin — Downloads',
            'content' => $content,
        ]));
    }
}
