<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;

final class KbImageController
{
    /** Raster types the browser previews inline; SVG uploads are downloaded instead — same reasoning as ticket attachments: an admin-supplied SVG could carry a script, and direct navigation to the URL (unlike an <img> reference) would execute it. */
    private const INLINE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly KbArticleRepository $articles,
        private readonly KbImageRepository $images,
        private readonly KbImageUploadService $uploads
    ) {
    }

    public function upload(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $articleId = (int) $params['id'];

        if ($this->articles->find($articleId) === null) {
            return Response::html('404 Not Found', 404);
        }

        $filesEntry = $request->file('images');

        if ($this->uploads->hasRealUpload($filesEntry)) {
            $result = $this->uploads->storeFromFilesEntry($filesEntry, $articleId);

            if ($result['errors'] !== []) {
                return Response::redirect("/admin/kb/articles/{$articleId}/edit?img_error=" . urlencode(implode(' ', $result['errors'])));
            }
        }

        return Response::redirect("/admin/kb/articles/{$articleId}/edit?img_uploaded=1");
    }

    public function delete(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $articleId = (int) $params['id'];
        $image = $this->images->find((int) $params['imgId']);

        if ($image === null || (int) $image['article_id'] !== $articleId) {
            return Response::html('404 Not Found', 404);
        }

        $this->uploads->deleteImage((int) $image['id']);

        return Response::redirect("/admin/kb/articles/{$articleId}/edit?img_deleted=1");
    }

    public function serveAdmin(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->serve((int) $params['id'], (int) $params['imgId']);
    }

    /**
     * Public, unauthenticated — any KB article's images are already visible
     * to anyone who can view the article itself on /kb/{id}, so this needs
     * no login, only the article/image pairing to be genuine.
     */
    public function servePublic(Request $request, array $params): Response
    {
        return $this->serve((int) $params['id'], (int) $params['imgId']);
    }

    private function serve(int $articleId, int $imageId): Response
    {
        $image = $this->images->find($imageId);

        if ($image === null || (int) $image['article_id'] !== $articleId) {
            return Response::html('404 Not Found', 404);
        }

        if ($image['source'] === 'ai_generated') {
            $svg = (string) $image['svg_content'];

            return (new Response($svg, 200))
                ->withHeader('Content-Type', 'image/svg+xml')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, max-age=3600')
                ->withHeader('Content-Length', (string) strlen($svg));
        }

        $file = $this->uploads->fileFor($image);

        if ($file === null) {
            return Response::html('404 Not Found', 404);
        }

        $disposition = in_array($file['mime'], self::INLINE_MIME_TYPES, true) ? 'inline' : 'attachment';
        $bytes = (string) file_get_contents($file['path']);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=3600')
            ->withHeader('Content-Disposition', $disposition . '; filename="' . str_replace('"', '', $file['name']) . '"')
            ->withHeader('Content-Length', (string) strlen($bytes));
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
}
