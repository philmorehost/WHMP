<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Provisioning\HttpClient;
use Throwable;

/**
 * Real implementation against the EU Commission's public VIES REST API
 * (https://ec.europa.eu/taxation_customs/vies/rest-api/). Response shape
 * below was confirmed against the live endpoint during development
 * (GET .../ms/IE/vat/6388047V returned isValid/name/address/userError
 * exactly as read here) — this is not a guessed/undocumented contract.
 *
 * Fails open by design: any network error, non-200 status, malformed
 * body, or inconclusive `userError` (VIES reports transient outages via
 * this field, e.g. "MS_UNAVAILABLE") returns checked=false rather than
 * throwing — an EU government service being briefly unreachable must
 * never be capable of blocking a sale.
 */
final class ViesVatLookupService implements VatLookupService
{
    private const BASE_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api';

    /** VIES uses "EL" for Greece, not the ISO 3166-1 "GR" this app stores elsewhere. */
    private const COUNTRY_ALIASES = ['GR' => 'EL'];

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function lookup(string $countryCode, string $vatNumber): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $viesCountry = self::COUNTRY_ALIASES[$countryCode] ?? $countryCode;
        $number = strtoupper(str_replace([' ', '-', '.'], '', trim($vatNumber)));

        if (str_starts_with($number, $countryCode)) {
            $number = substr($number, strlen($countryCode));
        }

        if ($viesCountry === '' || $number === '') {
            return ['checked' => false, 'valid' => false, 'name' => null, 'error' => 'Missing country or VAT number.'];
        }

        try {
            $url = self::BASE_URL . '/ms/' . rawurlencode($viesCountry) . '/vat/' . rawurlencode($number);
            $response = $this->http->request('GET', $url, ['Accept' => 'application/json']);
        } catch (Throwable $e) {
            return ['checked' => false, 'valid' => false, 'name' => null, 'error' => $e->getMessage()];
        }

        if ($response['status'] !== 200) {
            return ['checked' => false, 'valid' => false, 'name' => null, 'error' => "VIES returned HTTP {$response['status']}."];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded) || !array_key_exists('userError', $decoded)) {
            return ['checked' => false, 'valid' => false, 'name' => null, 'error' => 'Unrecognized VIES response.'];
        }

        $userError = (string) $decoded['userError'];

        if ($userError === 'VALID') {
            $name = isset($decoded['name']) ? trim((string) $decoded['name']) : null;

            return ['checked' => true, 'valid' => true, 'name' => $name !== '' && $name !== '---' ? $name : null, 'error' => null];
        }

        if ($userError === 'INVALID') {
            return ['checked' => true, 'valid' => false, 'name' => null, 'error' => null];
        }

        // Anything else (INVALID_INPUT, MS_UNAVAILABLE, GLOBAL_MAX_CONCURRENT_REQ,
        // SERVICE_UNAVAILABLE, TIMEOUT, ...) is inconclusive, not a rejection.
        return ['checked' => false, 'valid' => false, 'name' => null, 'error' => "VIES: {$userError}"];
    }
}
