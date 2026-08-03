<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

/**
 * The public page keys an admin can target a banner at, and how a request
 * path resolves to one. Kept as a small fixed list — matching the pattern of
 * CronInfoController::JOBS — rather than free-form URL patterns, since the
 * platform only has a handful of distinct public areas and an admin picking
 * from a checklist can't typo a regex that silently never matches.
 */
final class PromoBannerPages
{
    public const ALL = 'all';

    /** @var array<string, string> page key => label, in the order shown to the admin */
    public const PAGES = [
        'home' => 'Home page',
        'store' => 'Store & product pages',
        'cart' => 'Cart & checkout',
        'domains' => 'Domain search / register / transfer',
        'client' => 'Client dashboard & account area',
    ];

    /** Resolves the current request path to the page key a banner's target_pages would match against. */
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

        if (str_starts_with($path, '/client')) {
            return 'client';
        }

        return 'home';
    }
}
