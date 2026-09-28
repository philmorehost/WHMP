<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

/**
 * The reseller storefront this request belongs to, if any.
 *
 * Resolved once per request by the Kernel from the incoming Host header, and
 * then shared (registered as a singleton) so the theme layer, the brand helper
 * and anything else asked later all agree on which store is being served.
 *
 * **The security rule this class exists to enforce.** Elsewhere in this
 * codebase the Host header is deliberately NOT trusted: SeoTags builds
 * canonical URLs from APP_URL precisely so a spoofed Host cannot end up in
 * something a search engine or an email trusts. A storefront on a reseller's
 * own domain has to read the Host — so the compromise is:
 *
 *   - a Host only becomes a tenant by matching a row in `resellers` that an
 *     admin can see, and for a custom domain, one whose DNS *we* verified;
 *   - anything derived from it (canonical URLs, later the payment return URL)
 *     is built from `host()`, which is the host that actually matched — not
 *     from the raw header;
 *   - an unmatched host resolves to null and the request is served exactly as
 *     it was before this class existed.
 *
 * So the host is trusted because it matched a known store, never merely
 * because it arrived.
 */
final class CurrentReseller
{
    /** @var array<string, mixed>|null */
    private ?array $store = null;

    private ?string $host = null;

    /**
     * @param array<string, mixed>|null $store the matched resellers row
     * @param string|null $host the host that matched it, lowercase, no port
     */
    public function set(?array $store, ?string $host = null): void
    {
        $this->store = $store;
        $this->host = $store === null ? null : $host;
    }

    public function clear(): void
    {
        $this->store = null;
        $this->host = null;
    }

    /** @return array<string, mixed>|null */
    public function get(): ?array
    {
        return $this->store;
    }

    public function id(): ?int
    {
        return $this->store === null ? null : (int) $this->store['id'];
    }

    public function exists(): bool
    {
        return $this->store !== null;
    }

    public function isActive(): bool
    {
        return $this->store !== null && ($this->store['status'] ?? 'active') === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->store !== null && ($this->store['status'] ?? 'active') !== 'active';
    }

    /**
     * The host this store was matched on, or null on the platform site.
     *
     * Use this — never the raw Host header — when a URL must point at the
     * storefront the visitor is actually on.
     */
    public function host(): ?string
    {
        return $this->host;
    }

    /**
     * The brand the storefront should render, or an empty array when the
     * platform's own branding applies. Only non-empty values are returned, so
     * an unset field falls back to the admin's global theme rather than
     * blanking it out.
     *
     * @return array{brandName?: string, logoUrl?: string, faviconUrl?: string, primaryColor?: string}
     */
    public function brand(): array
    {
        if ($this->store === null) {
            return [];
        }

        $brand = [];

        foreach ([
            'brandName' => 'brand_name',
            'logoUrl' => 'logo_url',
            'faviconUrl' => 'favicon_url',
            'primaryColor' => 'primary_color',
        ] as $key => $column) {
            $value = trim((string) ($this->store[$column] ?? ''));

            if ($value !== '') {
                $brand[$key] = $value;
            }
        }

        return $brand;
    }
}
