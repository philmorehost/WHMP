<?php

declare(strict_types=1);

namespace CodeVault\Support;

/**
 * Renders admin-typed prose as HTML that keeps the shape it was typed in.
 *
 * Product descriptions, campaign bodies and email templates are all entered in
 * a plain textarea. The line breaks the admin uses to separate points mean
 * nothing once the text is dropped into HTML, so a carefully laid out
 * description collapses into one run-on paragraph — WHMCS renders these with
 * the breaks intact, and staff reasonably expect the same here.
 *
 * Anything already containing block-level markup is passed through untouched,
 * so an admin who deliberately writes HTML keeps full control.
 */
final class FormattedText
{
    /** Block-level tags that mean "this is already laid out as HTML". */
    private const BLOCK_TAGS = 'p|div|table|tr|td|h[1-6]|ul|ol|li|blockquote|section|article|header|footer|main|figure|hr';

    public static function isHtml(string $text): bool
    {
        return preg_match('/<(' . self::BLOCK_TAGS . ')\b[^>]*>/i', $text) === 1;
    }

    /**
     * @param string $text raw text as the admin typed it
     * @param string $paragraphStyle optional inline style for each <p>; used by
     *                               email, where <style> blocks get stripped by
     *                               Gmail and Outlook
     */
    public static function toHtml(string $text, string $paragraphStyle = ''): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return '';
        }

        if (self::isHtml($text)) {
            return $text;
        }

        // Escape first — a literal "<" or "&" the admin typed must not become
        // markup — then rebuild the structure they intended: a blank line
        // starts a paragraph, a single newline is a line break.
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $attribute = $paragraphStyle !== '' ? ' style="' . $paragraphStyle . '"' : '';
        $html = '';

        foreach (preg_split('/\n{2,}/', $escaped) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            $html .= '<p' . $attribute . '>' . nl2br($paragraph) . '</p>';
        }

        return $html;
    }

    /** Single-line, markup-free summary for list screens and meta tags. */
    public static function excerpt(string $text, int $length = 160): string
    {
        $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags(self::toHtml($text))));

        return mb_strimwidth($plain, 0, $length, '…');
    }
}
