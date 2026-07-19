<?php

declare(strict_types=1);

namespace CodeVault\Localization;

/**
 * A resolved language bound to its merged string catalog — handed to
 * views so every t() call in a template is a plain array lookup, with no
 * further DB/file access mid-render.
 */
final class Translation
{
    /**
     * @param array<string, mixed> $language
     * @param array<string, string> $catalog file-based strings, already overlaid with DB overrides
     */
    public function __construct(
        private readonly array $language,
        private readonly array $catalog
    ) {
    }

    public function code(): string
    {
        return (string) $this->language['code'];
    }

    public function isRtl(): bool
    {
        return (int) $this->language['is_rtl'] === 1;
    }

    public function dir(): string
    {
        return $this->isRtl() ? 'rtl' : 'ltr';
    }

    public function languageId(): int
    {
        return (int) $this->language['id'];
    }

    /** @param array<string, scalar> $replace */
    public function get(string $key, array $replace = []): string
    {
        $value = $this->catalog[$key] ?? $key;

        foreach ($replace as $name => $replacement) {
            $value = str_replace(":{$name}", (string) $replacement, $value);
        }

        return $value;
    }
}
