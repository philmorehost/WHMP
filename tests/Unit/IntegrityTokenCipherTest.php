<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Integrity\IntegrityTokenCipher;
use PHPUnit\Framework\TestCase;

final class IntegrityTokenCipherTest extends TestCase
{
    public function test_encrypt_then_decrypt_round_trips(): void
    {
        $cipher = new IntegrityTokenCipher('a-sufficiently-long-test-key-1234');

        $encrypted = $cipher->encrypt('CV-ACTIVATION-KEY-ABC123');

        $this->assertNotSame('CV-ACTIVATION-KEY-ABC123', $encrypted);
        $this->assertSame('CV-ACTIVATION-KEY-ABC123', $cipher->decrypt($encrypted));
    }

    public function test_each_encryption_uses_a_fresh_iv_so_ciphertext_differs(): void
    {
        $cipher = new IntegrityTokenCipher('a-sufficiently-long-test-key-1234');

        $first = $cipher->encrypt('same-plaintext');
        $second = $cipher->encrypt('same-plaintext');

        $this->assertNotSame($first, $second);
        $this->assertSame('same-plaintext', $cipher->decrypt($first));
        $this->assertSame('same-plaintext', $cipher->decrypt($second));
    }

    public function test_decrypting_with_the_wrong_key_does_not_return_the_original_plaintext(): void
    {
        $cipher = new IntegrityTokenCipher('key-one-is-long-enough-1234567890');
        $otherCipher = new IntegrityTokenCipher('key-two-is-long-enough-0987654321');

        $encrypted = $cipher->encrypt('secret-activation-key');

        $this->assertNotSame('secret-activation-key', $otherCipher->decrypt($encrypted));
    }

    public function test_decrypt_returns_null_for_garbage_input(): void
    {
        $cipher = new IntegrityTokenCipher('a-sufficiently-long-test-key-1234');

        $this->assertNull($cipher->decrypt('not-valid-base64-or-ciphertext!!'));
    }
}
