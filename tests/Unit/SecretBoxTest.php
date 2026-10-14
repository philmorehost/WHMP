<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Security\SecretBox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecretBoxTest extends TestCase
{
    public function test_round_trip_with_a_fresh_nonce_each_time(): void
    {
        $box = new SecretBox(null, str_repeat('k', 32));
        $a = $box->encrypt('MyAdmin-Pa55');
        $b = $box->encrypt('MyAdmin-Pa55');

        $this->assertStringStartsWith('sb1:', $a);
        $this->assertNotSame($a, $b);
        $this->assertStringNotContainsString('MyAdmin', $a);
        $this->assertSame('MyAdmin-Pa55', $box->decrypt($a));
    }

    public function test_another_key_tampering_and_foreign_values_decrypt_to_null(): void
    {
        $value = (new SecretBox(null, str_repeat('k', 32)))->encrypt('secret');
        $box = new SecretBox(null, str_repeat('z', 32));

        $this->assertNull($box->decrypt($value));
        $this->assertNull((new SecretBox(null, str_repeat('k', 32)))->decrypt(substr($value, 0, -2) . 'AA'));
        $this->assertNull($box->decrypt('plain-text'));
        $this->assertNull($box->decrypt(null));
    }

    public function test_a_missing_app_key_never_breaks_construction_only_encryption(): void
    {
        $box = new SecretBox(null, '');

        $this->assertFalse($box->available());
        $this->assertNull($box->decrypt('sb1:AAAA'));
        $this->expectException(RuntimeException::class);
        $box->encrypt('x');
    }
}
