<?php

declare(strict_types=1);

namespace CodeVault\Security;

/**
 * Verifier for PHPass "portable" hashes ($P$... / $H$...), the format
 * WHMCS <= 7.x (and WordPress/phpBB3) stored client passwords in. PHP's
 * native password_verify() understands bcrypt and Argon2 but NOT phpass,
 * so every client imported from an old WHMCS install would fail login
 * with "invalid email or password" even when typing the correct password.
 *
 * This is a direct port of Openwall's public-domain phpass algorithm
 * (encode64 + crypt_private), kept deliberately free of external
 * dependencies — same "implement the standard rather than take on a
 * library" stance as Totp/PdfDocument. It only VERIFIES; it never
 * generates new phpass hashes, since the login path transparently
 * upgrades an imported hash to Argon2id the first time the client logs
 * in successfully (see ClientAuthManager::attempt()).
 *
 * Verified against passlib's published test vectors in PhpassHasherTest
 * (including the $H$ phpBB3 prefix and a 1-round edge case from john).
 */
final class PhpassHasher
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function isPhpassHash(string $hash): bool
    {
        return strlen($hash) === 34
            && (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$'));
    }

    public function verify(string $password, string $storedHash): bool
    {
        if (strlen($password) > 4096 || !$this->isPhpassHash($storedHash)) {
            return false;
        }

        $countLog2 = strpos(self::ITOA64, $storedHash[3]);

        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return false;
        }

        $count = 1 << $countLog2;
        $salt = substr($storedHash, 4, 8);

        if (strlen($salt) !== 8) {
            return false;
        }

        $hash = md5($salt . $password, true);

        do {
            $hash = md5($hash . $password, true);
        } while (--$count);

        return hash_equals(substr($storedHash, 12), $this->encode64($hash, 16));
    }

    private function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;

        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3f];

            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }

            $output .= self::ITOA64[($value >> 6) & 0x3f];

            if ($i++ >= $count) {
                break;
            }

            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }

            $output .= self::ITOA64[($value >> 12) & 0x3f];

            if ($i++ >= $count) {
                break;
            }

            $output .= self::ITOA64[($value >> 18) & 0x3f];
        } while ($i < $count);

        return $output;
    }
}
