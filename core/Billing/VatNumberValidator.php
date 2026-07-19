<?php

declare(strict_types=1);

namespace CodeVault\Billing;

/**
 * EU/UK VAT number format validation (blueprint §4.4). Pure structural
 * checks — the official per-country format rules published alongside the
 * EU VIES service — not a live lookup (see VatLookupService for that). A
 * format-valid number is enough to trigger reverse-charge at checkout;
 * live verification is a separate, on-demand admin action since VIES
 * shouldn't be a hard dependency of the checkout path.
 */
final class VatNumberValidator
{
    /**
     * Country code => regex for the number after the 2-letter prefix is
     * stripped. Covers the EU VAT area (VIES's own country list, which
     * uses "EL" for Greece, not the ISO "GR") plus the UK (still VAT-area
     * relevant for reverse-charge with EU/NI trade, no longer VIES-checkable
     * post-Brexit but the format itself is unchanged).
     *
     * @var array<string, string>
     */
    private const FORMATS = [
        'AT' => '/^U\d{8}$/',
        'BE' => '/^0?\d{9}$/',
        'BG' => '/^\d{9,10}$/',
        'CY' => '/^\d{8}[A-Z]$/',
        'CZ' => '/^\d{8,10}$/',
        'DE' => '/^\d{9}$/',
        'DK' => '/^\d{8}$/',
        'EE' => '/^\d{9}$/',
        'EL' => '/^\d{9}$/',
        'GR' => '/^\d{9}$/',
        'ES' => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
        'FI' => '/^\d{8}$/',
        'FR' => '/^[A-Z0-9]{2}\d{9}$/',
        'GB' => '/^(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/',
        'HR' => '/^\d{11}$/',
        'HU' => '/^\d{8}$/',
        'IE' => '/^(\d{7}[A-Z]{1,2}|\d[A-Z]\d{5}[A-Z])$/',
        'IT' => '/^\d{11}$/',
        'LT' => '/^(\d{9}|\d{12})$/',
        'LU' => '/^\d{8}$/',
        'LV' => '/^\d{11}$/',
        'MT' => '/^\d{8}$/',
        'NL' => '/^\d{9}B\d{2}$/',
        'PL' => '/^\d{10}$/',
        'PT' => '/^\d{9}$/',
        'RO' => '/^\d{2,10}$/',
        'SE' => '/^\d{12}$/',
        'SI' => '/^\d{8}$/',
        'SK' => '/^\d{10}$/',
    ];

    public function isValidFormat(string $countryCode, string $vatNumber): bool
    {
        $countryCode = strtoupper(trim($countryCode));
        $number = strtoupper(str_replace([' ', '-', '.'], '', trim($vatNumber)));

        // Tolerate a caller-supplied country prefix (e.g. "DE123456789").
        if (str_starts_with($number, $countryCode)) {
            $number = substr($number, strlen($countryCode));
        }

        $pattern = self::FORMATS[$countryCode] ?? null;

        if ($pattern === null || $number === '') {
            return false;
        }

        return (bool) preg_match($pattern, $number);
    }

    /** @return array<int, string> */
    public function supportedCountries(): array
    {
        return array_keys(self::FORMATS);
    }
}
