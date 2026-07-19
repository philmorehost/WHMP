<?php

declare(strict_types=1);

namespace CodeVault\Api;

/**
 * A scoped API key/secret pair (blueprint §3 — "scoped API credentials/roles").
 * The secret is stored/compared as an Argon2id hash, never in the clear.
 */
final class ApiCredential
{
    /**
     * @param array<int, string> $scopes e.g. ["clients.read", "invoices.write"]
     */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $hashedSecret,
        public readonly array $scopes,
        public readonly bool $active = true,
    ) {
    }

    public function verifySecret(string $plainSecret): bool
    {
        return $this->active && password_verify($plainSecret, $this->hashedSecret);
    }

    public function hasScope(string $scope): bool
    {
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }

    public static function hashSecret(string $plainSecret): string
    {
        return password_hash($plainSecret, PASSWORD_ARGON2ID);
    }
}
