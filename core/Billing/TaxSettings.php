<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Settings\SettingsRepository;

/**
 * The seller's own home country for VAT purposes (blueprint §4.4
 * reverse-charge). A thin wrapper over the existing key/value
 * SettingsRepository, same pattern as ThemeSettings — reverse-charge is
 * meaningless without knowing which country is "domestic" vs
 * "cross-border" for this business, and unset (null) deliberately means
 * reverse-charge stays off rather than guessing a default.
 */
final class TaxSettings
{
    private const SELLER_COUNTRY_KEY = 'tax.seller_country_code';

    public function __construct(
        private readonly SettingsRepository $settings
    ) {
    }

    public function sellerCountryCode(): ?string
    {
        $value = $this->settings->get(self::SELLER_COUNTRY_KEY);

        return $value !== null && $value !== '' ? strtoupper($value) : null;
    }

    public function setSellerCountryCode(?string $countryCode): void
    {
        $this->settings->set(self::SELLER_COUNTRY_KEY, $countryCode !== null ? strtoupper(trim($countryCode)) : '');
    }
}
