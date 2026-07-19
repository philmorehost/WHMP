<?php

declare(strict_types=1);

namespace CodeVault\Api;

/**
 * Storage boundary for one-click-login tokens (blueprint §3). A Redis-backed
 * implementation (short TTL, single-use) lands alongside the queue system in
 * a later phase — kept as an interface now so SsoTokenManager isn't blocked
 * on that.
 */
interface SsoTokenStore
{
    public function put(string $token, array $payload, int $ttlSeconds): void;

    /**
     * Fetch and immediately invalidate — SSO tokens are single-use.
     */
    public function consume(string $token): ?array;
}
