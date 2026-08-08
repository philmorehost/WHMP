<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Security\PhpassHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the phpass portable-hash verifier against passlib's published
 * test vectors ($P$ and phpBB3's $H$ prefix) plus a john the ripper
 * vector that exercises the minimum allowed iteration count (rounds char
 * '6' => 2^6). These are external, canonical vectors — exactly the
 * "known-answer test against the reference implementation" approach used
 * for Totp/RFC 4226.
 */
final class PhpassHasherTest extends TestCase
{
    private PhpassHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PhpassHasher();
    }

    #[DataProvider('knownCorrectHashes')]
    public function test_known_correct_hashes_verify(string $password, string $hash): void
    {
        $this->assertTrue($this->hasher->verify($password, $hash));
    }

    public static function knownCorrectHashes(): array
    {
        return [
            'passlib empty password' => ['', '$P$7JaFQsPzJSuenezefD/3jHgt5hVfNH0'],
            'passlib compL3X!' => ['compL3X!', '$P$FiS0N5L672xzQx1rt1vgdJQRYKnQM9/'],
            'passlib test12345' => ['test12345', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0'],
            'passlib password example' => ['password', '$P$8ohUJ.1sdFw09/bMaAQPTGDNi2BIUt1'],
            'john openwall' => ['openwall', '$P$900000000m6YEJzWtTmNBBL4jypbHv1'],
            'john a' => ['a', '$P$9saltstriAcRMGl.91RgbAD6WSq64z.'],
            'john H prefix abcdefghi' => ['abcdefghi', '$H$9saltstriSUQTD.yC2WigjF8RU0Q.Z.'],
        ];
    }

    #[DataProvider('wrongPasswords')]
    public function test_wrong_password_never_verifies(string $password, string $hash): void
    {
        $this->assertFalse($this->hasher->verify($password, $hash));
    }

    public static function wrongPasswords(): array
    {
        return [
            ['wrong', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0'],
            ['', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0'],
            ['test12345', '$P$8ohUJ.1sdFw09/bMaAQPTGDNi2BIUt1'],
        ];
    }

    public function test_bcrypt_and_argon_hashes_are_not_treated_as_phpass(): void
    {
        $this->assertFalse($this->hasher->isPhpassHash(password_hash('secret', PASSWORD_BCRYPT)));
        $this->assertFalse($this->hasher->isPhpassHash(password_hash('secret', PASSWORD_ARGON2ID)));
        $this->assertFalse($this->hasher->verify('secret', password_hash('secret', PASSWORD_BCRYPT)));
    }

    public function test_malformed_phpass_hashes_are_rejected(): void
    {
        $this->assertFalse($this->hasher->verify('password', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r!L0'));
        $this->assertFalse($this->hasher->isPhpassHash('$P$9short'));
        $this->assertFalse($this->hasher->isPhpassHash(''));
        $this->assertFalse($this->hasher->verify('password', ''));
    }
}
