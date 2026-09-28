<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Theme\ThemeSettings;

/**
 * Everything a reseller store can be told to do, in one place.
 *
 * The admin page and the reseller's own page have different permissions but
 * must not have different rules — two implementations of "claim this domain"
 * would eventually disagree, and the disagreement would be a hostname served
 * for someone who never proved they own it. So both go through here, and the
 * controller's only job is deciding who is allowed to call it.
 *
 * Every method returns an `error` string meant for display, rather than
 * throwing, because every failure here is a normal thing a person does
 * (a taken slug, a domain someone else already claimed).
 */
final class ResellerStoreService
{
    public function __construct(
        private readonly ResellerStoreRepository $stores,
        private readonly ResellerStoreLocator $locator,
        private readonly DomainVerifier $verifier
    ) {
    }

    /** @return array<string, mixed>|null */
    public function forClient(int $clientId): ?array
    {
        return $this->stores->forClient($clientId);
    }

    /**
     * Creates the store, deriving its address label from the store name.
     *
     * @return array{success: bool, error: ?string, store: array<string, mixed>|null}
     */
    public function openForClient(int $clientId, string $storeName, ?string $slug = null): array
    {
        $store = $this->stores->forClient($clientId);

        if ($store !== null) {
            return ['success' => false, 'error' => 'This account already has a store.', 'store' => $store];
        }

        $brandName = trim($storeName);

        if ($brandName === '') {
            return ['success' => false, 'error' => 'Give your store a name — it is what your customers will see.', 'store' => null];
        }

        $candidate = ResellerStoreRepository::normaliseSlug($slug !== null && trim($slug) !== '' ? $slug : $brandName);

        if ($candidate === null) {
            return [
                'success' => false,
                'error' => 'That store name cannot be turned into a web address. Use letters and numbers (at least 3 characters) or type a different address.',
                'store' => null,
            ];
        }

        if ($this->stores->slugTaken($candidate)) {
            return ['success' => false, 'error' => 'The store address "' . $candidate . '" is already taken — choose another.', 'store' => null];
        }

        $id = $this->stores->create($clientId, $candidate, $brandName);

        return ['success' => true, 'error' => null, 'store' => $this->stores->find($id)];
    }

    /**
     * Brand only. A reseller may change how their store looks at any time; the
     * address and the domain are separate operations because changing those
     * can break a link someone published or a DNS record already set up.
     *
     * @param array<string, mixed> $brand
     * @return array{success: bool, error: ?string}
     */
    public function saveBrand(int $storeId, array $brand): array
    {
        $primaryColor = trim((string) ($brand['primary_color'] ?? ''));

        if ($primaryColor !== '' && !ThemeSettings::isValidHex($primaryColor)) {
            return ['success' => false, 'error' => 'Accent colour must be a hex value like #ff8f28.'];
        }

        foreach (['logo_url' => 'Logo', 'favicon_url' => 'Favicon'] as $field => $label) {
            $value = trim((string) ($brand[$field] ?? ''));

            if ($value !== '' && !self::isUsableImageUrl($value)) {
                return ['success' => false, 'error' => $label . ' must be an http(s) URL or an /uploads path.'];
            }
        }

        $this->stores->saveBrand($storeId, $brand);

        return ['success' => true, 'error' => null];
    }

    /** @return array{success: bool, error: ?string} */
    public function rename(int $storeId, string $slug): array
    {
        $candidate = ResellerStoreRepository::normaliseSlug($slug);

        if ($candidate === null) {
            return ['success' => false, 'error' => 'That address cannot be used. Letters, numbers and hyphens, 3-63 characters, and not a reserved word.'];
        }

        if ($this->stores->slugTaken($candidate, $storeId)) {
            return ['success' => false, 'error' => 'The store address "' . $candidate . '" is already taken.'];
        }

        $current = $this->stores->find($storeId);

        if ($current !== null && (string) $current['slug'] === $candidate) {
            return ['success' => true, 'error' => null];
        }

        $this->stores->renameSlug($storeId, $candidate);

        return ['success' => true, 'error' => null];
    }

    /**
     * Claims a custom domain, unverified. Reuses the hostname rules the reseller
     * API key's declared domain already uses — one definition of "a domain name"
     * across the whole reseller feature.
     *
     * @return array{success: bool, error: ?string, domain: ?string}
     */
    public function claimDomain(int $storeId, string $domain): array
    {
        $normalised = ResellerCredentialService::normaliseDomain($domain);

        if ($normalised === null) {
            return ['success' => false, 'error' => 'Enter a domain name like shop.example.com — letters, digits and hyphens only.', 'domain' => null];
        }

        if ($this->stores->domainTaken($normalised, $storeId)) {
            return ['success' => false, 'error' => $normalised . ' is already claimed by another store.', 'domain' => null];
        }

        // Claiming the platform's own host would let a store impersonate us on
        // the domain our own clients use.
        if ($normalised === $this->locator->platformHost() || str_ends_with($normalised, '.' . $this->locator->platformHost())) {
            return ['success' => false, 'error' => 'That domain belongs to the platform.', 'domain' => null];
        }

        $this->stores->setCustomDomain($storeId, $normalised, DomainVerifier::newToken());

        return ['success' => true, 'error' => null, 'domain' => $normalised];
    }

    /** @return array{success: bool, error: ?string} */
    public function releaseDomain(int $storeId): array
    {
        $this->stores->setCustomDomain($storeId, null, DomainVerifier::newToken());

        return ['success' => true, 'error' => null];
    }

    /**
     * Asks DNS whether the store controls its domain, and records it if so.
     *
     * @return array{verified: bool, method: ?string, found: array<int, string>, error: ?string, record_name: ?string, expected: ?string}
     */
    public function verifyDomain(int $storeId): array
    {
        $store = $this->stores->find($storeId);

        if ($store === null) {
            return ['verified' => false, 'method' => null, 'found' => [], 'error' => 'Store not found.', 'record_name' => null, 'expected' => null];
        }

        $domain = trim((string) ($store['custom_domain'] ?? ''));
        $token = trim((string) ($store['domain_verification_token'] ?? ''));

        if ($domain === '' || $token === '') {
            return ['verified' => false, 'method' => null, 'found' => [], 'error' => 'Claim a domain first.', 'record_name' => null, 'expected' => null];
        }

        $result = $this->verifier->verify($domain, $token, $this->locator->platformHost());

        if ($result['verified']) {
            $this->stores->markDomainVerified($storeId);
        }

        return $result + [
            'record_name' => $this->verifier->recordName($domain),
            'expected' => $this->verifier->expectedValue($token),
        ];
    }

    /** @return array{success: bool, error: ?string} */
    public function setStatus(int $storeId, string $status): array
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            return ['success' => false, 'error' => 'Unknown store status.'];
        }

        $this->stores->setStatus($storeId, $status);

        return ['success' => true, 'error' => null];
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->stores->all();
    }

    /**
     * The store's own web address, platform subdomain form — the one that works
     * before any DNS is set up. Built from APP_URL's scheme, never from the
     * request, so it is the same string everywhere it is shown.
     */
    public function platformUrl(array $store): string
    {
        return $this->locator->platformScheme() . '://' . $store['slug'] . '.' . $this->locator->platformHost();
    }

    /**
     * A logo/favicon must be a URL we can put in a src attribute, or a path
     * under /uploads. Anything else (javascript:, data: with markup, a bare
     * word) is refused: this value is rendered into every page of the store.
     */
    private static function isUsableImageUrl(string $value): bool
    {
        if (str_starts_with($value, '/uploads/')) {
            return !str_contains($value, '..');
        }

        return (bool) preg_match('~^https?://[^\s]+$~i', $value);
    }
}
