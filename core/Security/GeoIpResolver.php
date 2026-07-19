<?php

declare(strict_types=1);

namespace CodeVault\Security;

/**
 * Resolves an IP to an ISO 3166-1 alpha-2 country code for BruteGuard's
 * MaxMind country rules (blueprint §5). No `.mmdb` database file is
 * available in this environment yet, so the bound implementation is
 * NullGeoIpResolver (always "unknown") until a real MaxMind DB is wired up
 * — country rules simply have no effect until then, rather than the app
 * guessing at a country and getting blocking decisions wrong.
 */
interface GeoIpResolver
{
    public function resolveCountry(string $ipAddress): ?string;
}
