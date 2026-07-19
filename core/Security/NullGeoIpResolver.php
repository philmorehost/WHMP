<?php

declare(strict_types=1);

namespace CodeVault\Security;

final class NullGeoIpResolver implements GeoIpResolver
{
    public function resolveCountry(string $ipAddress): ?string
    {
        return null;
    }
}
