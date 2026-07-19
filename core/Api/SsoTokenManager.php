<?php

declare(strict_types=1);

namespace CodeVault\Api;

/**
 * Mints and redeems short-lived SSO tokens — drop a client/admin straight
 * into an authenticated session for embeds, support handoffs, or
 * control-panel single sign-on (blueprint §3).
 */
final class SsoTokenManager
{
    public function __construct(
        private readonly SsoTokenStore $store,
        private readonly int $defaultTtlSeconds = 60
    ) {
    }

    /**
     * @param array<string, mixed> $payload arbitrary data to carry (e.g. ['type' => 'client', 'id' => 42])
     */
    public function issue(array $payload, ?int $ttlSeconds = null): string
    {
        $token = bin2hex(random_bytes(32));
        $this->store->put($token, $payload, $ttlSeconds ?? $this->defaultTtlSeconds);

        return $token;
    }

    /**
     * @return array<string, mixed>|null null if the token was invalid, expired, or already used
     */
    public function redeem(string $token): ?array
    {
        return $this->store->consume($token);
    }
}
