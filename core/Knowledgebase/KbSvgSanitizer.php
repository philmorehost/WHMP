<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

/**
 * Turns a raw AI completion into a safe, servable SVG, or null if it can't.
 *
 * SVG is a bigger XSS surface than plain HTML — <script>, on*= handlers,
 * <foreignObject> (arbitrary embedded HTML), and external href/style
 * references can all execute or exfiltrate. This is allow-list-only rather
 * than a blocklist: only a small set of drawing primitives survive, so a tag
 * or attribute has to be explicitly permitted, not explicitly forbidden, to
 * come through. The result is still served via <img src>, never inlined
 * into the page — an <img>-loaded SVG can't execute script even if a gap
 * here let one through, which is the actual defense; this sanitizer is the
 * second layer, not the only one.
 */
final class KbSvgSanitizer
{
    private const MAX_LENGTH = 20000;

    /** Pure drawing primitives — deliberately no <style>, <a>, <use>, <image>, <foreignObject>, <script>. */
    private const ALLOWED_TAGS = '<svg><g><path><rect><circle><ellipse><line><polyline><polygon>'
        . '<text><tspan><defs><marker><lineargradient><radialgradient><stop><title><desc>';

    public static function sanitize(string $raw): ?string
    {
        $text = trim($raw);

        // Models like to wrap the whole answer in a code fence.
        $text = preg_replace('/^```[a-zA-Z]*\n?|\n?```$/', '', $text) ?? $text;

        if (preg_match('/<svg\b[^>]*>.*<\/svg>/is', $text, $m) !== 1) {
            return null;
        }

        $svg = $m[0];

        // Drop <script>/<style> with their contents first — strip_tags() below
        // removes the tags but keeps the text between them, which would
        // otherwise leave raw JS/CSS sitting in the output as visible text.
        $svg = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<(script|style)\b[^>]*>.*$#is', '', $svg) ?? $svg;

        $svg = strip_tags($svg, self::ALLOWED_TAGS);

        // Event handlers, any href (internal or external — nothing in the
        // allowed tag set legitimately needs one), and style attributes
        // (which could carry a CSS url() reference) all get stripped outright.
        $svg = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg) ?? $svg;
        $svg = preg_replace('/\s+(?:xlink:)?href\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg) ?? $svg;
        $svg = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $svg) ?? $svg;

        $svg = trim($svg);

        if (!str_starts_with(strtolower($svg), '<svg') || !str_contains(strtolower($svg), '</svg>')) {
            return null;
        }

        if (strlen($svg) > self::MAX_LENGTH) {
            return null;
        }

        return $svg;
    }
}
