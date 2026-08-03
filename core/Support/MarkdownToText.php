<?php

declare(strict_types=1);

namespace CodeVault\Support;

/**
 * Turns the Markdown that language models emit by habit into clean prose.
 *
 * Model output is written for a Markdown renderer, but the places staff paste
 * it — a campaign body, a ticket reply, an SMS — show it verbatim, so the
 * markup arrives as literal "###", "**" and "---" clutter around the words.
 *
 * Asking the model for plain text in the system prompt helps but is not
 * reliable; models drift back to Markdown, especially for anything list- or
 * heading-shaped. This runs over the answer afterwards so the result is clean
 * regardless of whether the instruction was followed.
 *
 * Structure is preserved rather than discarded: a heading stays on its own
 * line, a bullet becomes a real bullet character. The aim is text that reads
 * well as-is, not text with the formatting stripped out of it.
 */
final class MarkdownToText
{
    public static function convert(string $raw): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);

        // Fenced code blocks: drop the fence lines, keep the code.
        $text = preg_replace('/^[ \t]*```[a-zA-Z0-9_-]*[ \t]*$/m', '', $text) ?? $text;

        // Headings: "### Title" -> "Title". Trailing #'s are also valid syntax.
        $text = preg_replace('/^[ \t]{0,3}#{1,6}[ \t]+(.*?)[ \t]*#*[ \t]*$/m', '$1', $text) ?? $text;

        // Horizontal rules (---, ***, ___) become a blank separator line.
        $text = preg_replace('/^[ \t]{0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/m', '', $text) ?? $text;

        // Blockquotes: "> quoted" -> "quoted".
        $text = preg_replace('/^[ \t]{0,3}>[ \t]?/m', '', $text) ?? $text;

        // Links and images: "[label](url)" -> "label (url)"; a bare or
        // self-labelled link collapses to just the URL rather than repeating it.
        $text = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)[^)]*\)/', '$1 ($2)', $text) ?? $text;
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/',
            static function (array $m): string {
                $label = trim($m[1]);
                $url = trim($m[2]);

                return $label === '' || $label === $url ? $url : "{$label} ({$url})";
            },
            $text
        ) ?? $text;

        // Emphasis. Bold before italic, or the inner pair of "**x**" would be
        // consumed as italic markers and leave stray asterisks behind.
        $text = preg_replace('/\*\*\*(?=\S)(.+?)(?<=\S)\*\*\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/___(?=\S)(.+?)(?<=\S)___/s', '$1', $text) ?? $text;
        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/__(?=\S)(.+?)(?<=\S)__/s', '$1', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\w*])/', '$1', $text) ?? $text;
        // Underscore italics only between word boundaries, so snake_case_names
        // and file_names.txt survive untouched.
        $text = preg_replace('/(?<![\w_])_(?=\S)([^_\n]+?)(?<=\S)_(?![\w_])/', '$1', $text) ?? $text;

        // Inline code ticks.
        $text = preg_replace('/`([^`\n]+)`/', '$1', $text) ?? $text;

        // Bullets: "- item" / "* item" / "+ item" -> "• item", keeping indent
        // so nested lists still read as nested.
        $text = preg_replace('/^([ \t]*)[-*+][ \t]+/m', '$1• ', $text) ?? $text;

        // Collapse runs of blank lines left behind by removed rules/fences.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
