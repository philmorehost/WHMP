<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Support\FormattedText;

/**
 * Email-flavoured wrapper around FormattedText.
 *
 * Same problem as everywhere else — admin-typed prose losing its line breaks
 * once it becomes HTML — but email needs the paragraph spacing inlined on each
 * element, because Gmail and Outlook routinely drop <style> blocks.
 */
final class EmailContent
{
    /** Inline, because a <style> rule can't be relied on in an email client. */
    private const PARAGRAPH_STYLE = 'margin:0 0 16px 0;line-height:1.6;';

    public static function isHtml(string $body): bool
    {
        return FormattedText::isHtml($body);
    }

    public static function toHtml(string $body): string
    {
        return FormattedText::toHtml($body, self::PARAGRAPH_STYLE);
    }

    public static function excerpt(string $body, int $length = 160): string
    {
        return FormattedText::excerpt($body, $length);
    }
}
