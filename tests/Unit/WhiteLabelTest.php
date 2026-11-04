<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Provisioning\WhiteLabel;
use PHPUnit\Framework\TestCase;

/**
 * Upstream provider names never reach a client — reseller stores are
 * white-labelled. These pin the scrubber that guards every client-visible
 * ticket body and toast in the client service controller.
 */
final class WhiteLabelTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function providerTextProvider(): array
    {
        return [
            'the reported rdns ticket line' => [
                'Nocix publishes no reverse DNS endpoint, so this needs to be set manually.',
                'the server provider publishes no reverse DNS endpoint, so this needs to be set manually.',
            ],
            'api error mentioning the brand' => [
                'Could not reach the Nocix API.',
                'Could not reach the server provider API.',
            ],
            'http error explanation' => [
                'InterServer API error (HTTP 500).',
                'the server provider API error (HTTP 500).',
            ],
            'portal host' => [
                'Reload it in the my.nocix.net portal.',
                'Reload it in the provider portal portal.',
            ],
            'old manage host' => [
                'Could not reach manage.nocix.net',
                'Could not reach the provider portal',
            ],
            'lowercase brand' => [
                'the nocix service is not linked',
                'the server provider service is not linked',
            ],
            'interserver brand' => [
                'No InterServer API key is saved on this server record.',
                'No the server provider API key is saved on this server record.',
            ],
            'interserver host' => [
                'Could not list VPSes on my.interserver.net.',
                'Could not list VPSes on the provider portal.',
            ],
            'wholesaleinternet alias' => [
                'my.wholesaleinternet.net answers instead.',
                'the provider portal answers instead.',
            ],
            'mixed sentence' => [
                'Nocix and InterServer both refused.',
                'the server provider and the server provider both refused.',
            ],
        ];
    }

    /** @dataProvider providerTextProvider */
    public function test_provider_names_are_scrubbed(string $raw, string $expected): void
    {
        $this->assertSame($expected, WhiteLabel::text($raw));
    }

    public function test_clean_text_passes_through_untouched(): void
    {
        $this->assertSame(
            'The PTR for 69.197.183.10 could not be set via the VPS API (Invalid IP address).',
            WhiteLabel::text('The PTR for 69.197.183.10 could not be set via the VPS API (Invalid IP address).')
        );
    }

    public function test_substrings_of_other_words_are_safe(): void
    {
        // 'nocix' inside an unrelated word must not be mangled — the brands
        // are matched as their own literal text, and no product-ish word in
        // this codebase contains them; this pins the exact literal behaviour.
        $this->assertSame('server provider rocks', WhiteLabel::text('nocix rocks'));
    }
}
