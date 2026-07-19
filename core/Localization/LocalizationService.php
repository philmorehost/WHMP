<?php

declare(strict_types=1);

namespace CodeVault\Localization;

/**
 * Localization (blueprint §5): resolves the active language for a request
 * — session choice (works for guests) wins over the client's saved
 * profile preference, which wins over the system default — then loads
 * that language's file catalog (resources/lang/{code}.php) overlaid with
 * any admin-entered translation_overrides rows.
 *
 * Scope note: the file catalogs cover the storefront + common chrome
 * (header/footer/cart/checkout labels), not every string in the app —
 * translating the entire admin/client surface is out of scope for this
 * pass. See CodeVault_WHMCS_Parity_Build_Blueprint.md R11 status notes.
 */
final class LocalizationService
{
    public function __construct(
        private readonly LanguageRepository $languages,
        private readonly TranslationOverrideRepository $overrides,
        private readonly string $langPath
    ) {
    }

    /** @return array<string, mixed> */
    public function resolveForClient(?array $client): array
    {
        if ($client !== null && $client['language_id'] !== null) {
            $language = $this->languages->find((int) $client['language_id']);

            if ($language !== null && (int) $language['is_active'] === 1) {
                return $language;
            }
        }

        return $this->languages->default();
    }

    /** @return array<string, mixed> */
    public function resolveEffective(?array $client, ?int $sessionLanguageId): array
    {
        if ($sessionLanguageId !== null) {
            $language = $this->languages->find($sessionLanguageId);

            if ($language !== null && (int) $language['is_active'] === 1) {
                return $language;
            }
        }

        return $this->resolveForClient($client);
    }

    /** @param array<string, mixed> $language */
    public function translationFor(array $language): Translation
    {
        $catalog = $this->loadCatalog((string) $language['code']);
        $overrides = $this->overrides->mapForLanguage((int) $language['id']);

        return new Translation($language, $overrides + $catalog);
    }

    /** @return array<string, string> */
    private function loadCatalog(string $code): array
    {
        $path = "{$this->langPath}/{$code}.php";

        if (!is_file($path)) {
            $path = "{$this->langPath}/en.php";
        }

        /** @var array<string, string> $catalog */
        $catalog = require $path;

        return $catalog;
    }
}
