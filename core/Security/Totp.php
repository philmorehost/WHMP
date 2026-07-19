<?php

declare(strict_types=1);

namespace CodeVault\Security;

/**
 * RFC 6238 TOTP (blueprint §4.3 "2FA", never built through R0-R12) —
 * hand-rolled, zero external dependencies, consistent with the rest of
 * the codebase (mirrors PdfDocument's "implement the standard directly
 * rather than take on a library" approach). RFC 4226's dynamic
 * truncation is the well-defined, testable core; TOTP is just HOTP with
 * counter = floor(unixtime / period) — see TotpTest for verification
 * against RFC 4226's official published test vectors.
 *
 * No QR code image is generated — the provisioning URI and raw secret
 * are shown as text for manual entry, which every authenticator app
 * supports as a fallback to scanning. A hand-rolled QR encoder (Reed-
 * Solomon error correction, matrix placement) is a much larger, harder-
 * to-verify undertaking than TOTP itself, and manual entry is fully
 * functionally equivalent — just less convenient to type.
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const SECRET_BYTES = 20;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /** The otpauth:// URI an authenticator app can import (via a QR scan of it rendered elsewhere, or pasted directly). */
    public function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode("{$issuer}:{$accountName}");
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
    }

    public function currentCode(string $base32Secret, ?int $timestamp = null): string
    {
        return $this->hotp($base32Secret, intdiv($timestamp ?? time(), self::PERIOD));
    }

    /**
     * Accepts a code from the current time step or one step either side
     * (±30s) to tolerate clock drift between server and authenticator app.
     */
    public function verify(string $base32Secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = trim($code);

        if ($code === '' || !ctype_digit($code)) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->hotp($base32Secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** RFC 4226 HOTP: HMAC-SHA1(secret, counter) with dynamic truncation to a fixed-digit decimal code. */
    private function hotp(string $base32Secret, int $counter): string
    {
        $key = self::base32Decode($base32Secret);
        $counterBytes = pack('N', 0) . pack('N', $counter);
        $hash = hash_hmac('sha1', $counterBytes, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $code = (string) ($truncated % (10 ** self::DIGITS));

        return str_pad($code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';

        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    public static function base32Decode(string $data): string
    {
        $data = strtoupper(rtrim($data, '='));
        $bits = '';

        foreach (str_split($data) as $char) {
            $pos = strpos(self::ALPHABET, $char);

            if ($pos === false) {
                continue;
            }

            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                break;
            }

            $bytes .= chr(bindec($byte));
        }

        return $bytes;
    }
}
