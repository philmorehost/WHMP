<?php

declare(strict_types=1);

namespace CodeVault\Billing;

/**
 * On-demand live VAT number verification (blueprint §4.4 "live lookup,
 * e.g. VIES-style"). Deliberately separate from VatNumberValidator's
 * format check and never on the checkout hot path — an external
 * government service being slow or down should never block a sale.
 */
interface VatLookupService
{
    /**
     * @return array{checked: bool, valid: bool, name: ?string, error: ?string}
     *   checked=false means the lookup itself failed (network/service
     *   error, unsupported country) — the caller should treat this as
     *   "unknown", not "invalid". checked=true, valid=false means the
     *   authority definitively rejected the number.
     */
    public function lookup(string $countryCode, string $vatNumber): array;
}
