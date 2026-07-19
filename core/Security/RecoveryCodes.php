<?php

declare(strict_types=1);

namespace CodeVault\Security;

/**
 * One-time 2FA recovery codes — shown once at enable time, stored hashed
 * (same idiom as password_hash for real passwords), each consumable
 * exactly once. The account-lockout-avoidance path when someone loses
 * their authenticator device.
 */
final class RecoveryCodes
{
    private const COUNT = 8;
    private const RAW_LENGTH = 10;

    /** @return array<int, string> plain codes — must be shown to the user exactly once; never stored in plaintext */
    public function generate(): array
    {
        $codes = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            $codes[] = $this->format(strtoupper(bin2hex(random_bytes((int) ceil(self::RAW_LENGTH / 2)))));
        }

        return $codes;
    }

    /**
     * @param array<int, string> $plainCodes
     */
    public function hashForStorage(array $plainCodes): string
    {
        $hashed = array_map(
            fn (string $code) => password_hash($this->normalize($code), PASSWORD_DEFAULT),
            $plainCodes
        );

        return (string) json_encode($hashed);
    }

    /**
     * Checks a submitted code against the stored hashes; on a match,
     * returns the remaining hashes (JSON) with that one removed, so the
     * caller can persist it and the code can never be reused. Returns
     * null on no match — caller should leave the stored value untouched.
     */
    public function verifyAndConsume(string $submittedCode, ?string $storedHashesJson): ?string
    {
        $hashes = $storedHashesJson !== null ? (json_decode($storedHashesJson, true) ?: []) : [];

        if ($hashes === []) {
            return null;
        }

        $normalized = $this->normalize($submittedCode);

        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && password_verify($normalized, $hash)) {
                unset($hashes[$index]);

                return (string) json_encode(array_values($hashes));
            }
        }

        return null;
    }

    private function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    private function format(string $raw): string
    {
        return implode('-', str_split(substr($raw, 0, self::RAW_LENGTH), 4));
    }
}
