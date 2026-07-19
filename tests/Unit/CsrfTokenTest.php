<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use PHPUnit\Framework\TestCase;

final class CsrfTokenTest extends TestCase
{
    private string $emptyConfigDir;
    private CsrfToken $csrf;

    protected function setUp(): void
    {
        // Same trick as AuthGuardPermissionTest: drive $_SESSION directly
        // and point Config at a bare temp dir rather than the real
        // project root, so this never touches a real session lifecycle
        // or leaks .env state process-wide into other tests.
        $_SESSION = [];
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-csrf-test-' . uniqid();
        mkdir($this->emptyConfigDir);

        $session = new SessionManager(new Config($this->emptyConfigDir));
        $this->csrf = new CsrfToken($session);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->emptyConfigDir);
    }

    public function test_get_generates_a_token_on_first_call(): void
    {
        $this->assertArrayNotHasKey('_csrf_token', $_SESSION);

        $token = $this->csrf->get();

        $this->assertNotSame('', $token);
        $this->assertSame($token, $_SESSION['_csrf_token']);
    }

    public function test_get_returns_the_same_token_on_repeated_calls(): void
    {
        $first = $this->csrf->get();
        $second = $this->csrf->get();

        $this->assertSame($first, $second);
    }

    public function test_verify_accepts_the_current_token(): void
    {
        $token = $this->csrf->get();

        $this->assertTrue($this->csrf->verify($token));
    }

    public function test_verify_rejects_a_wrong_token(): void
    {
        $this->csrf->get();

        $this->assertFalse($this->csrf->verify('not-the-real-token'));
    }

    public function test_verify_rejects_a_missing_or_empty_submission(): void
    {
        $this->csrf->get();

        $this->assertFalse($this->csrf->verify(null));
        $this->assertFalse($this->csrf->verify(''));
    }

    public function test_verify_rejects_a_non_string_submission(): void
    {
        $this->csrf->get();

        $this->assertFalse($this->csrf->verify(['not' => 'a string']));
        $this->assertFalse($this->csrf->verify(12345));
    }

    public function test_verify_fails_closed_when_no_token_was_ever_generated(): void
    {
        $this->assertFalse($this->csrf->verify('anything'));
        $this->assertFalse($this->csrf->verify(null));
    }

    public function test_rotate_replaces_the_token(): void
    {
        $first = $this->csrf->get();
        $second = $this->csrf->rotate();

        $this->assertNotSame($first, $second);
        $this->assertFalse($this->csrf->verify($first));
        $this->assertTrue($this->csrf->verify($second));
    }

    public function test_generated_tokens_are_not_predictable_constants(): void
    {
        $token = $this->csrf->get();

        $_SESSION = [];

        $regenerated = $this->csrf->get();

        $this->assertNotSame($token, $regenerated);
    }
}
