<?php

declare(strict_types=1);

namespace CodeVault\Clients;

final class ClientAuthResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?array $client = null,
        public readonly ?string $reason = null
    ) {
    }

    public static function success(array $client): self
    {
        return new self('success', $client);
    }

    public static function needsTwoFactor(array $client): self
    {
        return new self('needs_2fa', $client);
    }

    public static function invalid(): self
    {
        return new self('invalid');
    }

    public static function blocked(string $reason): self
    {
        return new self('blocked', null, $reason);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function requiresTwoFactor(): bool
    {
        return $this->status === 'needs_2fa';
    }
}
