<?php

declare(strict_types=1);

namespace CodeVault\Security;

/**
 * The "forgot password" reset link's token — pure algorithm, no DB. A
 * high-entropy random token is what goes in the emailed link; only its
 * SHA-256 hash is ever stored, so a database leak alone doesn't yield a
 * usable reset link (same reasoning as password_hash for real passwords,
 * just a faster hash since this token is high-entropy and single-use rather
 * than a low-entropy user-chosen secret).
 */
final class PasswordResetToken
{
    private const BYTES = 32;

    /** @return array{token: string, hash: string} plain token — goes in the emailed link, never stored */
    public function generate(): array
    {
        $token = bin2hex(random_bytes(self::BYTES));

        return ['token' => $token, 'hash' => $this->hash($token)];
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function matches(string $submittedToken, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($submittedToken));
    }
}
