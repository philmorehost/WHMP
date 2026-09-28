<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Config;

/**
 * Works out which reseller storefront a request is for, from its Host header.
 *
 * This is the one place in the codebase that reads the Host header to make an
 * authorisation-shaped decision, so the rules are deliberately narrow:
 *
 *   1. The platform's own host is never a store — it falls through untouched.
 *   2. A single label in front of the platform host is a platform subdomain:
 *      `acme.{platform}` -> the store whose slug is `acme`.
 *   3. Any other host is a custom domain, and only matches a store whose
 *      domain we have DNS-VERIFIED (the test lives in the query, in
 *      ResellerStoreRepository::forVerifiedDomain()).
 *   4. Anything else returns null, and the request is served as it would have
 *      been before storefronts existed.
 *
 * A claim someone types into a form therefore never becomes a served hostname:
 * step 3 requires verification first. The returned `host` is the normalised host
 * that actually matched, which is the only host callers may build URLs from.
 */
final class ResellerStoreLocator
{
    public function __construct(
        private readonly ResellerStoreRepository $stores,
        private readonly Config $config
    ) {
    }

    /**
     * The host this install is served from, taken from APP_URL — the same
     * source SeoTags trusts, so "is this our own host?" cannot be answered
     * differently in two places.
     */
    public function platformHost(): string
    {
        $host = (string) (parse_url((string) $this->config->env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost');

        return self::normaliseHost($host);
    }

    /** The scheme this install is served with, from the same APP_URL. */
    public function platformScheme(): string
    {
        $scheme = (string) (parse_url((string) $this->config->env('APP_URL', 'http://localhost'), PHP_URL_SCHEME) ?: 'http');

        return in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
    }

    /**
     * @return array{store: array<string, mixed>, host: string}|null
     */
    public function resolve(string $host): ?array
    {
        $host = self::normaliseHost($host);

        if ($host === '' || $host === $this->platformHost()) {
            return null;
        }

        // `www.` on the platform's own domain is the platform, not a store.
        if ($host === 'www.' . $this->platformHost()) {
            return null;
        }

        $subdomain = $this->subdomainSlug($host);

        if ($subdomain !== null) {
            $store = $this->stores->forSlug($subdomain);

            return $store === null ? null : ['store' => $store, 'host' => $host];
        }

        $store = $this->stores->forVerifiedDomain($host);

        return $store === null ? null : ['store' => $store, 'host' => $host];
    }

    /**
     * The slug when $host is exactly one label in front of the platform host,
     * else null. A slug that normaliseSlug() would reject (too short, reserved
     * like `www`, or not a legal DNS label) is not a store address, so there is
     * no query to make.
     */
    private function subdomainSlug(string $host): ?string
    {
        $platform = $this->platformHost();
        $suffix = '.' . $platform;

        if ($platform === '' || !str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        // Only ONE label: `a.b.platform` is not a store address.
        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        return ResellerStoreRepository::normaliseSlug($label);
    }

    /** Lowercase, no port, no trailing dot — one spelling per host. */
    public static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));

        // IPv6 literals arrive bracketed; take the address inside.
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 1, $end - 1);
        } elseif (str_contains($host, ':')) {
            $host = (string) preg_replace('~:\d+$~', '', $host);
        }

        return rtrim($host, '.');
    }
}
