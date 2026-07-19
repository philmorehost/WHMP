<?php

declare(strict_types=1);

namespace CodeVault\Security;

final class BruteGuardVerdict
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
