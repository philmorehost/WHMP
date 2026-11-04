<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

/**
 * Upstream server-provider names never reach a client. Reseller stores are
 * white-labelled (the rule performOrTicket's refusal path already stated in
 * prose): module/API messages routinely interpolate provider brands —
 * "Nocix API error (HTTP 500)", ProviderHttpError::explain(…, 'InterServer'),
 * "reload it in the Nocix portal" — and every string that is about to land
 * in a client-visible support ticket body or client-area toast goes through
 * WhiteLabel::text() first. Admin surfaces (activity log, admin service
 * page) deliberately keep the real names: staff need them to operate.
 */
final class WhiteLabel
{
    /**
     * Longest/most specific first so "my.nocix.net" is consumed before the
     * bare brand would be — order is load-bearing.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        'my.nocix.net' => 'the provider portal',
        'manage.nocix.net' => 'the provider portal',
        'nocix.net' => 'the provider',
        'my.wholesaleinternet.net' => 'the provider portal',
        'wholesaleinternet.net' => 'the provider',
        'wholesale internet' => 'the provider',
        'nocix' => 'the server provider',
        'my.interserver.net' => 'the provider portal',
        'interserver.net' => 'the provider',
        'interserver' => 'the server provider',
    ];

    public static function text(string $text): string
    {
        foreach (self::NAMES as $name => $replacement) {
            $text = str_ireplace($name, $replacement, $text);
        }

        return $text;
    }}
