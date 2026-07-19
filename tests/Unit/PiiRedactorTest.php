<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Ai\PiiRedactor;
use PHPUnit\Framework\TestCase;

final class PiiRedactorTest extends TestCase
{
    public function test_redacts_email_addresses(): void
    {
        $result = PiiRedactor::redact('Reach me at jane.doe@example.com please.');

        $this->assertStringNotContainsString('jane.doe@example.com', $result);
        $this->assertStringContainsString('[redacted-email]', $result);
    }

    public function test_redacts_phone_numbers(): void
    {
        $result = PiiRedactor::redact('Call me on 08012345678 tomorrow.');

        $this->assertStringNotContainsString('08012345678', $result);
        $this->assertStringContainsString('[redacted-phone]', $result);
    }

    public function test_redacts_card_like_digit_runs(): void
    {
        $result = PiiRedactor::redact('My card number is 4111111111111111.');

        $this->assertStringNotContainsString('4111111111111111', $result);
        $this->assertStringContainsString('[redacted-card-number]', $result);
    }

    public function test_redacts_ip_addresses(): void
    {
        $result = PiiRedactor::redact('My server IP is 192.168.1.100.');

        $this->assertStringNotContainsString('192.168.1.100', $result);
        $this->assertStringContainsString('[redacted-ip]', $result);
    }

    public function test_leaves_ordinary_text_untouched(): void
    {
        $text = 'My website is down and returning a 500 error.';

        $this->assertSame($text, PiiRedactor::redact($text));
    }
}
