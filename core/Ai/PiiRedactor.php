<?php

declare(strict_types=1);

namespace CodeVault\Ai;

/**
 * Best-effort scrub of obvious PII (emails, phone numbers, card-like
 * digit runs, IP addresses) before ticket text leaves the platform for a
 * third-party AI API. Not a compliance guarantee — a last line of
 * defense against the copilot accidentally echoing a customer's contact
 * details or a pasted card number back through DeepSeek's servers.
 */
final class PiiRedactor
{
    public static function redact(string $text): string
    {
        $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[redacted-email]', $text) ?? $text;
        $text = preg_replace('/\b(?:\d[ -]*?){13,19}\b/', '[redacted-card-number]', $text) ?? $text;
        $text = preg_replace('/\b\+?\d{1,3}[-.\s]?\(?\d{2,4}\)?[-.\s]?\d{3,4}[-.\s]?\d{3,4}\b/', '[redacted-phone]', $text) ?? $text;
        $text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[redacted-ip]', $text) ?? $text;

        return $text;
    }
}
