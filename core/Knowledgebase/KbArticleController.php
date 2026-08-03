<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class KbArticleController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly KbArticleRepository $articles,
        private readonly KbCategoryRepository $categories,
        private readonly KbImageRepository $images
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('knowledgebase.articles-index', ['articles' => $this->articles->all()]);
    }

    public function createForm(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('knowledgebase.article-form', [
            'article' => null,
            'categories' => $this->categories->all(),
            'error' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $categoryId = (int) $request->input('category_id', 0);
        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body', ''));

        if ($categoryId <= 0 || $title === '' || $body === '') {
            return $this->render('knowledgebase.article-form', [
                'article' => null,
                'categories' => $this->categories->all(),
                'error' => 'Category, title, and body are required.',
            ]);
        }

        $id = $this->articles->create($categoryId, $title, $body);

        return Response::redirect("/admin/kb/articles/{$id}/edit");
    }

    public function editForm(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $article = $this->articles->find((int) $params['id']);

        if ($article === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('knowledgebase.article-form', [
            'article' => $article,
            'categories' => $this->categories->all(),
            'images' => $this->images->forArticle((int) $article['id']),
            'error' => null,
            'imgUploaded' => $request->query('img_uploaded') !== null,
            'imgDeleted' => $request->query('img_deleted') !== null,
            'imgError' => $request->query('img_error') !== null ? (string) $request->query('img_error') : null,
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $categoryId = (int) $request->input('category_id', 0);
        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body', ''));

        if ($categoryId <= 0 || $title === '' || $body === '') {
            return $this->render('knowledgebase.article-form', [
                'article' => $this->articles->find($id),
                'categories' => $this->categories->all(),
                'error' => 'Category, title, and body are required.',
            ]);
        }

        $this->articles->update($id, $categoryId, $title, $body);

        return Response::redirect("/admin/kb/articles/{$id}/edit");
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->articles->delete((int) $params['id']);

        return Response::redirect('/admin/kb/articles');
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
            'title' => 'CodeVault Admin — KB Articles',
            'content' => $content,
        ]));
    }
}
