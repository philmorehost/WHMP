<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Security\LocalRedirect;
use PHPUnit\Framework\TestCase;

/**
 * The one rule every user-supplied redirect must pass. The interesting
 * cases are the two shapes that LOOK local while leaving the site:
 * '//evil.com' (protocol-relative) and '/\evil.com' (backslash twin, which
 * several browsers treat as scheme-relative in a Location header). The
 * language switcher shipped with exactly that hole.
 */
final class LocalRedirectTest extends TestCase
{
    public function test_accepts_ordinary_local_paths(): void
    {
        $this->assertSame('/client/dashboard', LocalRedirect::sanitize('/client/dashboard', '/store'));
        $this->assertSame('/', LocalRedirect::sanitize('/', '/store'));
    }

    public function test_rejects_absolute_urls(): void
    {
        $this->assertSame('/store', LocalRedirect::sanitize('https://evil.test/phish', '/store'));
        $this->assertSame('/store', LocalRedirect::sanitize('http://evil.test', '/store'));
        $this->assertSame('/store', LocalRedirect::sanitize('javascript:alert(1)', '/store'));
    }

    public function test_rejects_protocol_relative_paths(): void
    {
        $this->assertSame('/store', LocalRedirect::sanitize('//evil.test', '/store'));
    }

    public function test_rejects_the_backslash_twin(): void
    {
        $this->assertSame('/store', LocalRedirect::sanitize('/\\evil.test', '/store'));
    }

    public function test_rejects_control_characters_and_empty_input(): void
    {
        $this->assertSame('/store', LocalRedirect::sanitize("/ok\r\nX-Injected: 1", '/store'));
        $this->assertSame('/store', LocalRedirect::sanitize("/ok\x00evil", '/store'));
        $this->assertSame('/store', LocalRedirect::sanitize('', '/store'));
        $this->assertSame('/store', LocalRedirect::sanitize('   ', '/store'));
    }

    public function test_a_relative_garbage_falls_back_rather_than_being_rewritten(): void
    {
        $this->assertSame('/cart', LocalRedirect::sanitize('cart', '/cart'));
    }
}
