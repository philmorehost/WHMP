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
