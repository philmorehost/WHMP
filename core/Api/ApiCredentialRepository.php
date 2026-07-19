<?php

declare(strict_types=1);

namespace CodeVault\Api;

/**
 * Lookup boundary for API credentials. The real (DB-backed) implementation
 * lands with Staff Management in R3 — kept as an interface now so the
 * authenticator has something stable to depend on today.
 */
interface ApiCredentialRepository
{
    public function findByKey(string $key): ?ApiCredential;
}
