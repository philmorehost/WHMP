<?php

declare(strict_types=1);

// Global helpers available to every (unnamespaced) view template.

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return \CodeVault\Support\App::container()->make(\CodeVault\Security\CsrfToken::class)->get();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('asset')) {
    /**
     * Builds a URL for a file under public/, appending the file's
     * modification time as a ?v= cache-buster. Static assets are served
     * with far-future cache headers by most hosts/CDNs (LiteSpeed, cPanel,
     * Cloudflare), so without this a redeployed app.js/CSS can keep being
     * served stale for a long time even after a hard refresh — the version
     * changes whenever the file does, forcing a fresh fetch.
     */
    function asset(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $file = dirname(__DIR__) . '/public' . $path;
        $version = is_file($file) ? (string) @filemtime($file) : '';

        return $path . ($version !== '' ? '?v=' . $version : '');
    }
}

if (!function_exists('img')) {
    /**
     * Builds a URL for an image through the WebP pipeline (`/img/{width}/…`).
     * The pipeline serves a WebP derivative to WebP-capable browsers and the
     * original otherwise — both with far-future cache headers — so a page
     * can downscale an uploaded photo or brand asset once and stop shipping
     * the full-size original to every visitor. A non-empty $width caps the
     * largest dimension (aspect ratio is preserved); pass 0/null for the
     * original size, converted to WebP where possible.
     *
     * Like asset(), appends a ?v= cache-buster so an updated file (or a
     * changed width) invalidates the browser/CDN cache. Falls back to a
     * plain asset() URL when the file doesn't exist, so a view can call
     * img() unconditionally.
     */
    function img(string $path, ?int $width = null): string
    {
        $path = '/' . ltrim($path, '/');
        $file = dirname(__DIR__) . '/public' . $path;
        $version = is_file($file) ? (string) @filemtime($file) : '';

        $query = http_build_query([
            'path' => $path,
            'w' => $width !== null && $width > 0 ? (int) $width : 0,
        ]) . ($version !== '' ? '&v=' . $version : '');

        return '/img?' . $query;
    }
}

if (!function_exists('page_title')) {
    /**
     * Renders a page title under the admin's own brand name.
     *
     * Titles are hardcoded at ~70 call sites as "CodeVault Admin — Clients"
     * and the like. Rather than editing every one (and relying on nobody ever
     * adding another), the product name is swapped for the configured brand
     * here, at the only place titles are actually printed.
     *
     * The brand is then appended if the title doesn't already carry it, so
     * "Affiliate Area" becomes "Affiliate Area — Acme Hosting" while
     * "Acme Hosting Admin — Clients" is left as it is rather than repeating.
     */
    function page_title(?string $title, ?string $brand): string
    {
        $brand = trim((string) $brand);

        if ($brand === '') {
            $brand = 'WHMP';
        }

        $title = trim((string) $title);

        // Case-insensitive so "codevault" in a stray title is caught too.
        $title = trim(str_ireplace('CodeVault', $brand, $title));

        // Collapse a doubled brand left by a title that already named it,
        // e.g. "Acme — Acme" or "Acme Acme Admin".
        $title = trim((string) preg_replace(
            '/' . preg_quote($brand, '/') . '(\s*[—\-|:]\s*|\s+)' . preg_quote($brand, '/') . '/i',
            $brand,
            $title
        ));

        if ($title === '' || strcasecmp($title, $brand) === 0) {
            return $brand;
        }

        if (stripos($title, $brand) !== false) {
            return $title;
        }

        return $title . ' — ' . $brand;
    }
}

if (!function_exists('brand_name')) {
    /**
     * The company name to show clients, as set by the admin.
     *
     * Reads theme.brand_name (Configuration → Theme), falling back to the
     * APP_NAME env value and finally a neutral product name. Used for the
     * {{company_name}} placeholder in outgoing email, which was hardcoded to
     * the product's own name in several jobs — so clients received renewal and
     * overdue notices signed off by software they've never heard of.
     *
     * Resolved through the container rather than injected because the callers
     * are a mix of jobs, services and views with very different constructors;
     * adding a dependency to each would mean touching the Kernel's hand-wired
     * bindings, which is a larger and riskier change for a display string.
     */
    function brand_name(): string
    {
        // Keyed by the site being served, not a single slot. This used to be one
        // `static $cached`, which is correct only while a process serves exactly
        // one brand — true for a web request, false for a cron run that renders
        // mail for several reseller storefronts, which would sign all of them
        // with the first reseller's name.
        //
        // The memo itself lives in BrandNameCache rather than a `static` here,
        // because a function-level static cannot be cleared from outside: nothing
        // could drop it when a brand actually changed, and no test could start
        // from a clean slate. The suite recreates its tables between tests, so
        // store ids repeat and a stale 'store:1' was served to another store.
        $key = 'platform';
        $storeName = '';

        try {
            $tenant = \CodeVault\Support\App::container()->make(\CodeVault\Reseller\CurrentReseller::class);

            if ($tenant->exists()) {
                $key = 'store:' . $tenant->id();
                $storeName = trim((string) ($tenant->get()['brand_name'] ?? ''));
            }
        } catch (\Throwable) {
            // No container yet (installer, CLI bootstrap) — platform brand.
        }

        if (\CodeVault\Theme\BrandNameCache::has($key)) {
            return \CodeVault\Theme\BrandNameCache::get($key);
        }

        $name = $storeName;

        try {
            $container = \CodeVault\Support\App::container();

            if ($name === '') {
                $name = trim((string) ($container->make(\CodeVault\Settings\SettingsRepository::class)->get('theme.brand_name', '') ?? ''));
            }

            if ($name === '') {
                $name = trim((string) $container->make(\CodeVault\Config::class)->env('APP_NAME', ''));
            }
        } catch (\Throwable) {
            // No container/DB yet (installer, CLI bootstrap) — fall through.
        }

        $resolved = $name !== '' ? $name : 'WHMP';
        \CodeVault\Theme\BrandNameCache::put($key, $resolved);

        return $resolved;
    }
}

if (!function_exists('brand_name_forget')) {
    /**
     * Drop the memoised brand for one site, or for every site.
     *
     * Needed wherever a brand is CHANGED in a process that may already have
     * asked for it: without this, saving a new brand and then rendering an email
     * in the same request signs the mail with the old name. Tests call it between
     * cases, because the memo outlives any single test in the process.
     */
    function brand_name_forget(?string $site = null): void
    {
        \CodeVault\Theme\BrandNameCache::forget($site);
    }
}

if (!function_exists('current_storefront')) {
    /**
     * The reseller store this request is being served as, or null on the
     * platform's own site (and whenever there is no container yet — installer,
     * CLI bootstrap, a view rendered in isolation by a test).
     *
     * Views use this to choose the storefront chrome (premium header, SERVICES
     * menu, footer) over the platform's client-area chrome. It never decides
     * anything about money or access — those read CurrentReseller through
     * constructor injection like everything else.
     *
     * @return array<string, mixed>|null
     */
    function current_storefront(): ?array
    {
        try {
            return \CodeVault\Support\App::container()->make(\CodeVault\Reseller\CurrentReseller::class)->get();
        } catch (\Throwable) {
            return null;
        }
    }
}

if (!function_exists('platform_premium_site')) {
    /**
     * True when this request is on the MAIN website and the main website uses
     * the premium design (Admin → Theme → Website design). Always false on a
     * store's host — a store has its own chrome — and whenever there is no
     * container or settings yet, so isolated view renders keep the classic look.
     */
    function platform_premium_site(): bool
    {
        return \CodeVault\Theme\PlatformSite::premiumEnabled();
    }
}

if (!function_exists('active_promo_banner')) {
    /**
     * The one promo banner (if any) targeted at the current request path ON THE
     * SITE BEING SERVED, or null — the platform's banners on the platform, a
     * store's own banners on that store. Resolved through the container rather than
     * injected because layouts.client is shared by every public controller —
     * adding a constructor dependency to each just to thread this through
     * would be a much larger change for a single popup.
     *
     * @return array<string, mixed>|null
     */
    function active_promo_banner(): ?array
    {
        try {
            $container = \CodeVault\Support\App::container();
            $repo = $container->make(\CodeVault\Marketing\PromoBannerRepository::class);
            $pageKey = \CodeVault\Marketing\PromoBannerPages::keyForPath((string) ($_SERVER['REQUEST_URI'] ?? '/'));

            // WHICH SITE is being served decides whose banners are eligible: the
            // platform's own banners on the platform, a store's own banners on
            // that store — never the platform's popup on a reseller's website.
            // Resolving the tenant can only fail before the container is built,
            // and the catch below then shows nothing, which is the safe side.
            $resellerId = $container->make(\CodeVault\Reseller\CurrentReseller::class)->id();
            $banner = $repo->activeForPage($pageKey, $resellerId);

            if ($banner !== null) {
                $repo->incrementImpressions((int) $banner['id']);
            }

            return $banner;
        } catch (\Throwable) {
            // No container/DB yet (installer, CLI bootstrap), or the table
            // doesn't exist yet mid-migration — a missing popup is never
            // worth breaking the page over.
            return null;
        }
    }
}

if (!function_exists('csp_nonce')) {
    /**
     * The current request's CSP nonce, for the app's own inline <script> tags.
     *
     * Usage: <script nonce="<?= csp_nonce() ?>"> ... </script>
     * Without it the browser blocks the block outright and the control it
     * powers silently does nothing.
     */
    function csp_nonce(): string
    {
        return \CodeVault\Security\SecurityHeaders::nonce();
    }
}
