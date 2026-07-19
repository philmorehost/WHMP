<?php

declare(strict_types=1);

namespace CodeVault\Integrity;

use RuntimeException;

/**
 * AES-256-CBC encrypt/decrypt for the on-disk activation token: a random
 * IV per encryption, prefixed to the ciphertext, base64-encoded for safe
 * file storage.
 */
final class IntegrityTokenCipher
{
    private const CIPHER = 'aes-256-cbc';

    public function __construct(
        private readonly string $key
    ) {
        if (strlen($this->key) < 16) {
            throw new RuntimeException('Activation encryption key is too short — set APP_KEY.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->derivedKey(), OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('Activation token encryption failed.');
        }

        return base64_encode($iv . $ciphertext);
    }

    public function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if (strlen($raw) <= $ivLength) {
            return null;
        }

        $iv = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->derivedKey(), OPENSSL_RAW_DATA, $iv);

        return $plaintext === false ? null : $plaintext;
    }

    private function derivedKey(): string
    {
        return hash('sha256', $this->key, true);
    }
}
