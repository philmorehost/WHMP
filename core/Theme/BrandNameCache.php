<?php

declare(strict_types=1);

namespace CodeVault\Theme;

/**
 * The per-site memo behind the global brand_name() helper.
 *
 * It lives in a class rather than a `static` inside the helper for one reason:
 * the memo's lifetime is the PROCESS, and a function-level static cannot be
 * cleared from outside it. That had two consequences, one of which was a real
 * bug rather than a test inconvenience:
 *
 *  - Nothing could invalidate it when the brand actually changed. An admin who
 *    saved a new brand name and then rendered an email in the same request would
 *    sign it with the OLD name, because the memo had already answered for that
 *    site and nothing dropped it.
 *  - No test could start from a clean slate. The suite DROPS and recreates its
 *    tables between tests, so AUTO_INCREMENT restarts and store ids repeat; a
 *    stale 'store:1' entry written by one test was then served to an unrelated
 *    store in another. That is the failure mode that made a branding test pass
 *    or fail depending on what had run before it.
 *
 * Keying by site is still correct and deliberate -- a cron run rendering mail
 * for several storefronts must not sign them all with the first reseller's name.
 * Only the lifetime needed fixing.
 */
final class BrandNameCache
{
    /** @var array<string, string> keyed by 'platform' or 'store:<id>' */
    private static array $names = [];

    public static function has(string $site): bool
    {
        return array_key_exists($site, self::$names);
    }

    public static function get(string $site): string
    {
        return self::$names[$site];
    }

    public static function put(string $site, string $name): void
    {
        self::$names[$site] = $name;
    }

    /**
     * Forget one site, or every site when none is named.
     *
     * Called after a brand is saved, and by tests between cases. Forgetting
     * everything is the safe default: it costs one settings read and cannot
     * leave a stale name behind.
     */
    public static function forget(?string $site = null): void
    {
        if ($site === null) {
            self::$names = [];

            return;
        }

        unset(self::$names[$site]);
    }
}
