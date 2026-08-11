<?php

declare(strict_types=1);

namespace CodeVault\Media;

use CodeVault\Request;
use CodeVault\Response;
use Throwable;

/**
 * Serves the WebP pipeline endpoint: `/img/{width}/{path}`.
 *
 * Paths resolve under public/assets (and public/uploads) only — the
 * whitelist keeps a crafted {path} from reading arbitrary server files.
 * When the client advertises WebP support (Accept header) and GD can
 * produce a derivative, the WebP is served; otherwise the original bytes
 * are sent. Both carry far-future cache headers because the canonical
 * `img()` helper (see core/helpers.php) appends a ?v= cache-buster.
 */
final class ImageController
{
    /** @var array<int, string> base dirs a {path} may resolve into, longest first */
    private const ALLOWED_BASES = [
        '/public/assets',
        '/public/uploads',
    ];

    public function __construct(
        private readonly WebpImageService $webp,
        private readonly string $basePath
    ) {
    }

    public function serve(Request $request, array $params): Response
    {
        $width = (int) $request->query('w', 0);
        $path = trim((string) $request->query('path', ''));

        if ($path === '' || str_contains($path, '..')) {
            return Response::html('404 Not Found', 404);
        }

        $relative = '/' . ltrim($path, '/');
        $candidate = $this->basePath . '/public' . $relative;
        $real = realpath($candidate);

        if ($real === false || !is_file($real)) {
            return Response::html('404 Not Found', 404);
        }

        // Belt-and-braces after the '..' rejection: the resolved file must
        // still sit under one of the allowed bases (public/assets or
        // public/uploads), so a symlink or a future path bug can't read
        // arbitrary server files through this endpoint.
        $allowed = false;
        foreach (self::ALLOWED_BASES as $base) {
            $baseReal = realpath($this->basePath . $base);
            if ($baseReal !== false && str_starts_with($real, rtrim($baseReal, '/') . DIRECTORY_SEPARATOR)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return Response::html('404 Not Found', 404);
        }

        $info = @getimagesize($real);
        $originalMime = $info !== false ? (string) ($info['mime'] ?? 'application/octet-stream') : 'application/octet-stream';

        // Only raster images go through the WebP pipeline.
        $isRaster = in_array($originalMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
        $wantsWebp = str_contains($this->acceptHeader($request), 'image/webp');

        $cacheHeaders = [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($isRaster && $wantsWebp) {
            try {
                $webpPath = $this->webp->derivative($real, $width > 0 ? $width : null);

                if ($webpPath !== null) {
                    $bytes = (string) file_get_contents($webpPath);

                    return (new Response($bytes, 200))
                        ->withHeader('Content-Type', 'image/webp')
                        ->withHeader('Content-Length', (string) strlen($bytes))
                        ->withHeader('Cache-Control', $cacheHeaders['Cache-Control'])
                        ->withHeader('X-Content-Type-Options', $cacheHeaders['X-Content-Type-Options']);
                }
            } catch (Throwable) {
                // Fall through to the original — never break an image over a
                // conversion hiccup.
            }
        }

        $bytes = (string) file_get_contents($real);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', $originalMime)
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Cache-Control', $cacheHeaders['Cache-Control'])
            ->withHeader('X-Content-Type-Options', $cacheHeaders['X-Content-Type-Options']);
    }

    /**
     * The Accept header, resolved case-insensitively. Request::capture()
     * normalizes HTTP_* keys to title case ("Accept") while header() looks
     * them up uppercased ("ACCEPT") — so the naive lookup never matches in
     * production. Scan the raw map instead.
     */
    private function acceptHeader(Request $request): string
    {
        foreach ($request->headers() as $name => $value) {
            if (strcasecmp((string) $name, 'Accept') === 0) {
                return (string) $value;
            }
        }

        return '';
    }
}
