<?php

declare(strict_types=1);

namespace CodeVault\Downloads;

use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Seo\SeoTags;
use CodeVault\View;

/**
 * Public downloads area (blueprint §4.1): browsable file library, no
 * login required — same as WHMCS's public Downloads tab. Files live
 * outside the public webroot (storage/downloads/) and are streamed
 * through this controller so download_count stays accurate.
 */
final class PublicDownloadController
{
    public function __construct(
        private readonly View $view,
        private readonly DownloadRepository $downloads,
        private readonly DownloadCategoryRepository $categories,
        private readonly string $storagePath,
        private readonly SeoTags $seo
    ) {
    }

    public function index(Request $request): Response
    {
        $categories = $this->categories->all();

        foreach ($categories as &$category) {
            $category['downloads'] = $this->downloads->forCategory((int) $category['id']);
        }
        unset($category);

        $content = $this->view->render('downloads.public-index', ['categories' => $categories]);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Downloads',
            'content' => $content,
            'canonicalUrl' => $this->seo->canonicalUrl('/downloads'),
            'metaDescription' => 'Download software, tools, and resources for your hosting account.',
            'jsonLd' => [$this->seo->organization()],
        ]));
    }

    public function download(Request $request, array $params): Response
    {
        $download = $this->downloads->find((int) $params['id']);

        if ($download === null) {
            return Response::html('404 Not Found', 404);
        }

        $path = $this->storagePath . '/' . $download['file_path'];

        if (!is_file($path)) {
            return Response::html('404 Not Found', 404);
        }

        $this->downloads->incrementDownloadCount((int) $download['id']);

        $filename = $download['name'];
        $extension = pathinfo((string) $download['file_path'], PATHINFO_EXTENSION);

        if ($extension !== '') {
            $filename .= '.' . $extension;
        }

        return (new Response((string) file_get_contents($path), 200))
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) filesize($path));
    }
}
