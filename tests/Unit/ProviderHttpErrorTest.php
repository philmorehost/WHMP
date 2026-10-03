<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Provisioning\ProviderHttpError;
use PHPUnit\Framework\TestCase;

final class ProviderHttpErrorTest extends TestCase
{
    private const BLOCK_1020 = <<<'HTML'
<!DOCTYPE html><html><head><title>Access denied | my.interserver.net used Cloudflare to restrict access</title></head><body>
<div class="cf-error-details"><h1>Access denied</h1><span class="cf-error-code">1020</span></div>
<div class="cf-error-footer"><span>Cloudflare Ray ID: <strong class="font-semibold">8c1f2a3b4d5e6f70</strong></span>
<span id="cf-footer-item-ip">Your IP: <button type="button" id="cf-footer-ip-reveal">Click to reveal</button>
<span class="hidden" id="cf-footer-ip">203.0.113.45</span></span><span>Performance &amp; security by Cloudflare</span></div>
</body></html>
HTML;

    public function test_a_1020_block_page_names_the_code_ip_and_ray_id(): void
    {
        $message = ProviderHttpError::explain(['status' => 403, 'body' => self::BLOCK_1020], 'InterServer');

        $this->assertStringContainsString('error 1020', $message);
        $this->assertStringContainsString('203.0.113.45', $message);
        $this->assertStringContainsString('8c1f2a3b4d5e6f70', $message);
        $this->assertStringContainsString('allow API access from 203.0.113.45', $message);
        $this->assertStringContainsString('PROVIDER_HTTP_PROXY', $message);
    }

    public function test_an_old_style_1010_page_and_a_bot_check_are_told_apart(): void
    {
        $old = '<title>Access denied | Cloudflare</title><h1>Error 1010</h1> Ray ID: 7a6b5c4d3e2f1a0b &bull; Your IP: 198.51.100.7';
        $details = ProviderHttpError::cloudflareDetails($old);
        $this->assertSame(['code' => '1010', 'ip' => '198.51.100.7', 'ray' => '7a6b5c4d3e2f1a0b', 'challenge' => false], $details);
        $this->assertStringContainsString('User-Agent', ProviderHttpError::explain(['status' => 403, 'body' => $old], 'InterServer'));

        $challenge = '<title>Just a moment...</title><script src="/cdn-cgi/challenge-platform/h/b/orchestrate"></script>';
        $message = ProviderHttpError::explain(['status' => 503, 'body' => $challenge], 'InterServer');
        $this->assertStringContainsString('bot check', $message);
        $this->assertStringContainsString("your web server's IP address", $message);
    }

    public function test_plain_errors_are_unchanged(): void
    {
        $this->assertStringContainsString('rejected the API key', ProviderHttpError::explain(['status' => 401, 'body' => '{}'], 'InterServer'));
        $this->assertStringContainsString('HTTP 500', ProviderHttpError::explain(['status' => 500, 'body' => 'Oops'], 'Nocix'));
    }
}
