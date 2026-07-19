<?php

declare(strict_types=1);

namespace CodeVault\Auth;

final class AuthResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?array $admin = null,
        public readonly ?string $reason = null
    ) {
    }

    public static function success(array $admin): self
    {
        return new self('success', $admin);
    }

    public static function needsTwoFactor(array $admin): self
    {
        return new self('needs_2fa', $admin);
    }

    public static function invalid(): self
    {
        return new self('invalid');
    }

    public static function locked(): self
    {
        return new self('locked');
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
