<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

use CodeVault\Support\FormattedText;

/**
 * Turns a KB article's plain-text body plus its attached images into
 * displayable HTML — the one place both the admin preview and the public
 * article page go through, so they can never drift apart.
 *
 * Paragraphs come from FormattedText::toHtml() (the same fix already applied
 * to campaign emails and client notifications — blank lines become <p>,
 * single newlines become <br>). An admin (or the AI copilot) can place an
 * image at a specific point by typing its [[image:ID]] token, shown next to
 * each image in the edit page for exactly this purpose; any image never
 * referenced by a token is appended at the end instead of silently dropped.
 */
final class KbArticleRenderer
{
    /**
     * @param array<int, array<string, mixed>> $images
     */
    public static function render(string $body, array $images, string $imageUrlPrefix): string
    {
        $html = FormattedText::toHtml($body);

        if ($images === []) {
            return $html;
        }

        $byId = [];
        foreach ($images as $image) {
            $byId[(int) $image['id']] = $image;
        }

        $used = [];
        $html = preg_replace_callback(
            '/\[\[image:(\d+)\]\]/',
            static function (array $m) use ($byId, $imageUrlPrefix, &$used): string {
                $id = (int) $m[1];

                if (!isset($byId[$id])) {
                    // Token points at an image that no longer exists — drop
                    // it silently rather than showing a broken reference.
                    return '';
                }

                $used[$id] = true;

                return self::figure($byId[$id], $imageUrlPrefix);
            },
            $html
        ) ?? $html;

        foreach ($images as $image) {
            if (!isset($used[(int) $image['id']])) {
                $html .= self::figure($image, $imageUrlPrefix);
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $image */
    private static function figure(array $image, string $imageUrlPrefix): string
    {
        $src = rtrim($imageUrlPrefix, '/') . '/' . (int) $image['id'];
        $caption = trim((string) ($image['caption'] ?? ''));
        $alt = $caption !== '' ? $caption : 'Illustration';

        $html = '<figure style="margin:var(--cv-space-4) 0;">'
            . '<img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy" style="max-width:100%;border-radius:8px;display:block;">';

        if ($caption !== '') {
            $html .= '<figcaption style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin-top:var(--cv-space-1);">' . e($caption) . '</figcaption>';
        }

        return $html . '</figure>';
    }
}
