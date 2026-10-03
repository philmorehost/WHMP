<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Config;
use RuntimeException;

/**
 * Encrypts small secrets kept in the database (a provider account password, for
 * example), so a database dump or backup alone does not reveal them.
 *
 * AES-256-GCM, with a random nonce per value. The key is derived from APP_KEY, so the
 * secret is only as safe as the .env file, which is the same trust boundary as the
 * API keys the app already holds. The stored form is `sb1:` + base64(nonce|tag|ct).
 * The prefix makes values recognisable and allows the format to change later.
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'sb1:';

    /** Null when APP_KEY is unusable: nothing can be encrypted, nothing decrypts. */
    private readonly ?string $key;

    /**
     * Never throws: this is built for every request that touches provisioning, and a
     * missing APP_KEY must not take those pages down. It only matters on encrypt().
     */
    public function __construct(?Config $config = null, ?string $key = null)
    {
        $material = $key ?? (string) ($config?->env('APP_KEY', '') ?? '');

        $this->key = strlen($material) >= 16 ? hash('sha256', 'codevault-secretbox|' . $material, true) : null;
    }

    public function available(): bool
    {
        return $this->key !== null;
    }

    public function encrypt(string $plaintext): string
    {
        if ($this->key === null) {
            throw new RuntimeException('APP_KEY is missing or too short, so secrets cannot be encrypted.');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Secret encryption failed.');
        }

        return self::PREFIX . base64_encode($nonce . $tag . $ciphertext);
    }

    /** The plaintext, or null when the value is not ours or was encrypted with another key. */
    public function decrypt(?string $stored): ?string
    {
        if ($this->key === null || $stored === null || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $plaintext = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plaintext === false ? null : $plaintext;
    }
}
