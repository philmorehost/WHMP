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
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $name = '';

        try {
            $container = \CodeVault\Support\App::container();
            $name = trim((string) ($container->make(\CodeVault\Settings\SettingsRepository::class)->get('theme.brand_name', '') ?? ''));

            if ($name === '') {
                $name = trim((string) $container->make(\CodeVault\Config::class)->env('APP_NAME', ''));
            }
        } catch (\Throwable) {
            // No container/DB yet (installer, CLI bootstrap) — fall through.
        }

        return $cached = ($name !== '' ? $name : 'WHMP');
    }
}

if (!function_exists('active_promo_banner')) {
    /**
     * The one promo banner (if any) an admin has targeted at the current
     * request path, or null. Resolved through the container rather than
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
            $banner = $repo->activeForPage($pageKey);

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
