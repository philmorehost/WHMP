<?php

declare(strict_types=1);

namespace CodeVault\Theme;

use CodeVault\Settings\SettingsRepository;

/**
 * Client-area branding (blueprint §5 "theme system") — a thin wrapper
 * over the existing key/value SettingsRepository rather than a dedicated
 * table, since it's exactly the kind of incrementally-added setting that
 * store already exists for.
 */
final class ThemeSettings
{
    private const BRAND_NAME_KEY = 'theme.brand_name';
    private const LOGO_URL_KEY = 'theme.logo_url';
    private const PRIMARY_COLOR_KEY = 'theme.primary_color';
    private const TERMS_URL_KEY = 'theme.terms_url';

    private const DEFAULT_BRAND_NAME = 'CodeVault';
    private const DEFAULT_PRIMARY_COLOR = '#ff8f28';

    public function __construct(
        private readonly SettingsRepository $settings
    ) {
    }

    /** @return array{brandName: string, logoUrl: ?string, primaryColor: string, primaryColorDark: string} */
    public function get(): array
    {
        $primaryColor = $this->settings->get(self::PRIMARY_COLOR_KEY, self::DEFAULT_PRIMARY_COLOR) ?? self::DEFAULT_PRIMARY_COLOR;
        if ($primaryColor === '#2f6fed') {
            $primaryColor = '#ff8f28';
        }

        $companyName = trim((string) ($this->settings->get('company.name') ?? ''));
        $configuredBrand = $this->settings->get(self::BRAND_NAME_KEY);
        
        $brandName = (!empty($configuredBrand) && $configuredBrand !== self::DEFAULT_BRAND_NAME) 
            ? $configuredBrand 
            : ($companyName !== '' ? $companyName : self::DEFAULT_BRAND_NAME);

        return [
            'brandName' => $brandName,
            'logoUrl' => $this->settings->get(self::LOGO_URL_KEY) ?: null,
            'primaryColor' => $primaryColor,
            'primaryColorDark' => $this->darken($primaryColor, 0.82),
            // Full URL of the Terms of Service page (typically hosted on the
            // company's primary marketing domain). Empty until an admin sets
            // it; the /terms route redirects here when present.
            'termsUrl' => $this->settings->get(self::TERMS_URL_KEY) ?: null,
        ];
    }

    public function save(string $brandName, ?string $logoUrl, string $primaryColor, ?string $termsUrl = null): void
    {
        $this->settings->set(self::BRAND_NAME_KEY, $brandName !== '' ? $brandName : self::DEFAULT_BRAND_NAME);
        $this->settings->set(self::LOGO_URL_KEY, $logoUrl ?? '');
        $this->settings->set(self::PRIMARY_COLOR_KEY, $this->isValidHex($primaryColor) ? $primaryColor : self::DEFAULT_PRIMARY_COLOR);
        $this->settings->set(self::TERMS_URL_KEY, $termsUrl ?? '');
    }

    /** The configured Terms of Service URL, or null if the admin hasn't set one. */
    public function termsUrl(): ?string
    {
        return $this->settings->get(self::TERMS_URL_KEY) ?: null;
    }

    public function isValidHex(string $color): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $color);
    }

    /** Multiplies each RGB channel by $factor (< 1 darkens) — enough to derive a hover shade from one admin-picked color without a full color library. */
    private function darken(string $hex, float $factor): string
    {
        if (!$this->isValidHex($hex)) {
            return $hex;
        }

        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        $clamp = static fn (int $channel): int => max(0, min(255, (int) round($channel * $factor)));

        return sprintf('#%02x%02x%02x', $clamp($r), $clamp($g), $clamp($b));
    }
}
