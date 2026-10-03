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
 *   2. A single label in front of the STORE DOMAIN is a store's free address:
 *      `acme.{store domain}` -> the store whose slug is `acme`. The store domain
 *      is set by the super admin (ResellerPlatformAddress). When it is not set,
 *      no free addresses exist and this step never matches.
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
        private readonly Config $config,
        // Where store subdomains live. Optional and trailing so hand-built instances
        // keep working. Without it, the store domain falls back to the platform host
        // (the behaviour before the setting existed). The container always supplies
        // it, so on a real install the admin's setting decides.
        private readonly ?ResellerPlatformAddress $address = null
    ) {
    }

    /**
     * The domain store subdomains are served under, or null when the super admin
     * has not set one. In that case stores are reachable only on their own
     * verified domains.
     */
    public function storeDomain(): ?string
    {
        if ($this->address === null) {
            return $this->platformHost();
        }

        return $this->address->domain();
    }

    /**
     * The store's free address (`{slug}.{store domain}`), or null when no store
     * domain is configured.
     *
     * @param array<string, mixed> $store
     */
    public function platformAddressFor(array $store): ?string
    {
        return ResellerPlatformAddress::addressFor((string) ($store['slug'] ?? ''), $this->storeDomain());
    }

    /**
     * The host the store is ACTUALLY served on: its verified custom domain, else its
     * free address. Null means the store has no working address yet.
     *
     * @param array<string, mixed> $store
     */
    public function publicHostFor(array $store): ?string
    {
        $custom = self::normaliseHost((string) ($store['custom_domain'] ?? ''));

        if ($custom !== '' && !empty($store['domain_verified_at'])) {
            return $custom;
        }

        return $this->platformAddressFor($store);
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
     * The public address of a store, for links written OUTSIDE a request to it —
     * an email sent by a cron job has no Host header to copy.
     *
     * The verified custom domain when there is one, because that is the address the
     * reseller advertises; otherwise the platform subdomain, which always works. An
     * unverified custom domain is never used: we do not serve it yet, so a link to it
     * would be a dead link with the reseller's name on it.
     *
     * @param array<string, mixed> $store
     */
    public function baseUrlFor(array $store): string
    {
        return $this->platformScheme() . '://' . $this->hostFor($store);
    }

    /**
     * A host NAME for the store, for places that always need one: a mail sender
     * domain, a label. This is publicHostFor() when the store has a working address.
     * Otherwise it falls back to the domain it has claimed, and then to its slug under
     * the platform host. Use publicHostFor() wherever the answer has to actually load.
     *
     * @param array<string, mixed> $store
     */
    public function hostFor(array $store): string
    {
        $public = $this->publicHostFor($store);

        if ($public !== null) {
            return $public;
        }

        $claimed = self::normaliseHost((string) ($store['custom_domain'] ?? ''));

        return $claimed !== '' ? $claimed : strtolower((string) ($store['slug'] ?? '')) . '.' . $this->platformHost();
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

        // The store domain itself, and its www, are not stores either.
        $storeDomain = $this->storeDomain();

        if ($storeDomain !== null && ($host === $storeDomain || $host === 'www.' . $storeDomain)) {
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
     * The slug when $host is exactly one label in front of the store domain,
     * else null (always null when no store domain is configured). A slug that normaliseSlug() would reject (too short, reserved
     * like `www`, or not a legal DNS label) is not a store address, so there is
     * no query to make.
     */
    private function subdomainSlug(string $host): ?string
    {
        $platform = (string) $this->storeDomain();
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
