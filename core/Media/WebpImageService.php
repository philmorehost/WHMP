<?php

declare(strict_types=1);

namespace CodeVault\Media;

use Throwable;

/**
 * On-the-fly WebP image pipeline (blueprint performance gap: no WebP
 * pipeline, no caching-header layer — every page shipped original-size
 * JPEG/PNG with default cache headers).
 *
 * Converts a raster image (jpeg/png/gif/webp) to WebP and optionally
 * downscales it to a max width, caching the result on disk so the first
 * request pays the conversion cost and every later one is a straight file
 * read. The cache key includes the source file's mtime, so touching the
 * original invalidates derivatives automatically.
 *
 * GD is required; if it (or WebP support) is missing, the pipeline degrades
 * to serving the original file rather than erroring — a performance
 * enhancement must never break a working site.
 */
final class WebpImageService
{
    private readonly string $cacheDir;

    public function __construct(
        string $basePath,
        private readonly ?int $defaultQuality = 82
    ) {
        $this->cacheDir = $basePath . '/storage/cache/webp';
    }

    /**
     * Returns the path to a WebP derivative of $source (creating it if
     * missing), or null when the source isn't a convertible image or GD is
     * unavailable — callers then fall back to the original.
     *
     * @param int|null $maxWidth 0/null = original size
     */
    public function derivative(string $source, ?int $maxWidth = null): ?string
    {
        if (!function_exists('imagewebp') || !is_file($source)) {
            return null;
        }

        $real = realpath($source);

        if ($real === false) {
            return null;
        }

        $info = @getimagesize($real);

        if ($info === false) {
            return null;
        }

        $mime = (string) ($info['mime'] ?? '');

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return null;
        }

        $width = (int) ($info[0] ?? 0);
        $maxWidth = $maxWidth !== null && $maxWidth > 0 ? (int) $maxWidth : $width;
        $maxWidth = min($maxWidth, $width); // never upscale

        // Cache key: source path + mtime + target width + quality. Bumping
        // the file or the quality setting re-derives automatically.
        $mtime = (string) @filemtime($real);
        $key = hash('sha256', $real . '|' . $mtime . '|' . $maxWidth . '|' . $this->defaultQuality);
        $cachePath = $this->cacheDir . '/' . $key . '.webp';

        if (is_file($cachePath)) {
            return $cachePath;
        }

        try {
            if (!is_dir($this->cacheDir)) {
                @mkdir($this->cacheDir, 0755, true);
            }

            $image = $this->load($real, $mime);

            if ($image === null) {
                return null;
            }

            if ($maxWidth > 0 && $width > $maxWidth) {
                $newHeight = (int) round(($info[1] ?? $width) * ($maxWidth / $width));
                $scaled = imagescale($image, $maxWidth, $newHeight);

                if ($scaled !== false) {
                    imagedestroy($image);
                    $image = $scaled;
                }
            }

            $ok = imagewebp($image, $cachePath, $this->defaultQuality);
            imagedestroy($image);

            return $ok ? $cachePath : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function load(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => null,
        };
    }
}
