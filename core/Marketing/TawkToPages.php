<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

/**
 * The page keys an admin can show the Tawk.To chat widget on, and how a
 * request path resolves to one. Mirrors PromoBannerPages — a small fixed
 * list rather than free-form URL patterns, so an admin picking from a
 * checklist can't typo a regex that silently never matches — extended to
 * cover the knowledgebase, downloads and the admin panel, which a live
 * support widget legitimately wants to be on.
 */
final class TawkToPages
{
    public const ALL = 'all';

    /** @var array<string, string> page key => label, in the order shown to the admin */
    public const PAGES = [
        'home' => 'Home page',
        'store' => 'Store & product pages',
        'cart' => 'Cart & checkout',
        'domains' => 'Domain search / register / transfer',
        'kb' => 'Knowledgebase',
        'downloads' => 'Downloads',
        'client' => 'Client dashboard & account area',
        'admin' => 'Admin panel',
    ];

    /** Resolves the current request path to the page key a widget's target_pages would match against. */
    public static function keyForPath(string $path): string
    {
        $path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/');

        if ($path === '/') {
            return 'home';
        }

        if (str_starts_with($path, '/store')) {
            return 'store';
        }

        if (str_starts_with($path, '/cart')) {
            return 'cart';
        }

        if (str_starts_with($path, '/domains')) {
            return 'domains';
        }

        if (str_starts_with($path, '/kb')) {
            return 'kb';
        }

        if (str_starts_with($path, '/downloads')) {
            return 'downloads';
        }

        if (str_starts_with($path, '/client')) {
            return 'client';
        }

        if (str_starts_with($path, '/admin')) {
            return 'admin';
        }

        return 'home';
    }
}
