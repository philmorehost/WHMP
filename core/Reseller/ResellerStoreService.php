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

    /** Longest headline the hero is designed for; matches the column (migration 0207). */
    public const HOME_HEADLINE_MAX = 120;

    /** Longest tagline; matches the column. */
    public const HOME_TAGLINE_MAX = 300;

    /**
     * The words at the top of the store's home page. Plain text only: both are
     * escaped on output, and a tag typed here would show up as literal text,
     * so it is refused instead of being stored to confuse the reseller later.
     *
     * @return array{success: bool, error: ?string}
     */
    public function saveHomepage(int $storeId, string $headline, string $tagline): array
    {
        $headline = trim(preg_replace('/\s+/u', ' ', $headline) ?? '');
        $tagline = trim(preg_replace('/\s+/u', ' ', $tagline) ?? '');

        if (mb_strlen($headline) > self::HOME_HEADLINE_MAX) {
            return ['success' => false, 'error' => 'Keep the headline to ' . self::HOME_HEADLINE_MAX . ' characters or fewer.'];
        }

        if (mb_strlen($tagline) > self::HOME_TAGLINE_MAX) {
            return ['success' => false, 'error' => 'Keep the tagline to ' . self::HOME_TAGLINE_MAX . ' characters or fewer.'];
        }

        if ($headline !== strip_tags($headline) || $tagline !== strip_tags($tagline)) {
            return ['success' => false, 'error' => 'The headline and tagline are plain text — remove any HTML tags.'];
        }

        $this->stores->saveHomepage($storeId, $headline, $tagline);

        return ['success' => true, 'error' => null];
    }

    /**
     * The store's own support chat.
     *
     * Every value is validated here rather than in the form, because the Tawk id
     * is interpolated into JavaScript the application generates: a form-only check
     * would be bypassed by any other caller, and the consequence is a reseller's
     * script running on a page we serve.
     *
     * Refusals explain WHAT to paste, not just that it was wrong. The id field is
     * the obvious place to paste Tawk's whole embed — their own instructions tell
     * you to copy a block — so the common mistake is named specifically.
     *
     * @param array<string, mixed> $chat
     * @return array{success: bool, error: ?string}
     */
    public function saveChat(int $storeId, array $chat): array
    {
        $whatsappRaw = trim((string) ($chat['support_whatsapp'] ?? ''));
        $propertyRaw = trim((string) ($chat['tawk_property_id'] ?? ''));
        $widgetRaw = trim((string) ($chat['tawk_widget_id'] ?? ''));

        $whatsapp = ResellerChat::normaliseWhatsapp($whatsappRaw);

        if ($whatsappRaw !== '' && $whatsapp === null) {
            return [
                'success' => false,
                'error' => 'That is not a WhatsApp number — include the country code, like +234 801 234 5678 '
                    . '(8 to 15 digits).',
            ];
        }

        $property = ResellerChat::normaliseTawkProperty($propertyRaw);

        if ($propertyRaw !== '' && $property === null) {
            return [
                'success' => false,
                'error' => ResellerChat::looksLikePastedEmbed($propertyRaw)
                    ? 'That is Tawk.To\'s whole embed code. Paste only the property id from the address they give you '
                        . '— embed.tawk.to/<id>/<widget> — and we add the code ourselves.'
                    : 'That is not a Tawk.To property id. It is the long id in the address Tawk gives you: '
                        . 'embed.tawk.to/<id>/<widget>.',
            ];
        }

        $widget = ResellerChat::normaliseTawkWidget($widgetRaw);

        if ($widgetRaw !== '' && $widget === null) {
            return [
                'success' => false,
                'error' => 'That is not a valid widget id — letters, numbers, dash and underscore only.',
            ];
        }

        $this->stores->saveChat($storeId, [
            'support_whatsapp' => $whatsapp,
            'tawk_property_id' => $property,
            // Cleared with the property, so removing Tawk.To cannot leave a widget
            // id behind that would attach itself to whatever property is set next.
            'tawk_widget_id' => $property === null ? null : $widget,
        ]);

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
     * A SHORT address suggestion from whatever name we have.
     *
     * Short on purpose, and it is the point of the method rather than a detail.
     * The address becomes a link the reseller reads out, prints on a card and types
     * into a phone, and the field would otherwise auto-fill from their whole store
     * name. So "Adeola Integrated Ventures Nigeria" should suggest `adeola`, not
     * `adeola-integrated-ventures-nigeria` — which is a web address nobody will ever
     * say correctly.
     *
     * Returns null when nothing usable is left, which leaves the field EMPTY for the
     * reseller to fill in rather than pre-filling something meaningless they might
     * accept without reading.
     */
    public function suggestSlug(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        // First word first, then each following one — so "Admin Panel" suggests
        // `panel` rather than nothing at all. `normaliseSlug()` refuses reserved
        // infrastructure names (www, api, admin, mail, ...), and returning the first
        // word's refusal would leave the field empty for a name that has a perfectly
        // good second word in it.
        foreach (preg_split('~[\s\-_]+~', $name) ?: [] as $word) {
            // Anything a hostname cannot carry — dots, ampersands, quotes — goes. A
            // word that is nothing but punctuation leaves nothing and is skipped.
            $cleaned = (string) preg_replace('~[^A-Za-z0-9]+~', '', (string) $word);

            if (strlen($cleaned) > 20) {
                $cleaned = substr($cleaned, 0, 20);
            }

            $candidate = ResellerStoreRepository::normaliseSlug($cleaned);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Claims a custom domain, unverified. Reuses the hostname rules the reseller
     * API key's declared domain already uses — one definition of "a domain name"
     * across the whole reseller feature.
     *
     * SAVING THE SAME DOMAIN AGAIN IS NOT A NEW REQUEST. Submitting resets a
     * store to 'pending' and clears the DNS proof (a proof belongs to a specific
     * domain), so treating an unchanged value as a fresh claim would take a live
     * store dark — and now would also take its domain back off the hosting panel
     * — just because someone pressed "Update domain" twice. Refusing to do that
     * is why this returns `unchanged`.
     *
     * A REFUSED domain is the exception, and is deliberately given a SECOND
     * CHANCE: the rejection reason tells the reseller to change something and try
     * again, so submitting again has to put it back in the queue rather than
     * bounce off the guard.
     *
     * @return array{success: bool, error: ?string, domain: ?string, unchanged?: bool}
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

        // Likewise the store domain: every name under it is some store's free address.
        $storeDomain = $this->locator->storeDomain();

        if ($storeDomain !== null && ($normalised === $storeDomain || str_ends_with($normalised, '.' . $storeDomain))) {
            return ['success' => false, 'error' => 'That domain is the platform address domain. Your free address under it is already yours; claim a domain of your own here.', 'domain' => null];
        }

        $existing = $this->stores->find($storeId);
        $current = $existing === null
            ? ''
            : ResellerStoreLocator::normaliseHost((string) ($existing['custom_domain'] ?? ''));

        if ($existing !== null
            && $current !== ''
            && $normalised === $current
            && (string) ($existing['domain_status'] ?? 'none') !== 'rejected'
        ) {
            return ['success' => true, 'error' => null, 'domain' => $normalised, 'unchanged' => true];
        }

        $this->stores->setCustomDomain($storeId, $normalised, DomainVerifier::newToken());

        return ['success' => true, 'error' => null, 'domain' => $normalised, 'unchanged' => false];
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
            $this->stores->markDomainVerified($storeId, (string) $result['method']);
        }

        return $result + [
            'record_name' => $this->verifier->recordName($domain),
            'expected' => $this->verifier->expectedValue($token),
        ];
    }

    /**
     * An admin forcing the domain verification, or taking it back.
     *
     * Exists because some DNS providers cannot be queried the way
     * DomainVerifier needs them to be (no TXT records, or a proxy in front),
     * and because a verification that turns out to have been wrong has to be
     * removable without deleting the store. Both directions are recorded as
     * 'manual' / cleared, so the store itself shows that a human decided this
     * rather than DNS — an override must not become indistinguishable from a
     * real proof once the activity log has scrolled away.
     *
     * Un-verifying leaves the domain claimed: it stays reserved to this store,
     * it just stops being served.
     *
     * @return array{success: bool, error: ?string}
     */
    public function overrideDomainVerification(int $storeId, bool $verified): array
    {
        $store = $this->stores->find($storeId);

        if ($store === null) {
            return ['success' => false, 'error' => 'Store not found.'];
        }

        if (trim((string) ($store['custom_domain'] ?? '')) === '') {
            return ['success' => false, 'error' => 'That store has not claimed a domain.'];
        }

        if ($verified) {
            $this->stores->markDomainVerified($storeId, 'manual');

            return ['success' => true, 'error' => null];
        }

        $this->stores->clearDomainVerification($storeId);

        return ['success' => true, 'error' => null];
    }

    /**
     * The steps between a store existing and it being live on its own domain,
     * in order, with whatever is still missing and what to do about it.
     *
     * The last two steps carry `manual` with `done => null`, which is the honest
     * answer rather than a gap: pointing the domain at this server and holding a
     * TLS certificate for it happen on the server, outside this application. We
     * can report that the step is outstanding; PHP cannot perform it.
     *
     * @param array<string, mixed> $store
     * @return array<int, array{key: string, label: string, done: ?bool, manual: bool, detail: string}>
     */
    public function goLiveChecklist(array $store): array
    {
        $domain = trim((string) ($store['custom_domain'] ?? ''));
        $verified = ($store['domain_verified_at'] ?? null) !== null;
        $active = ($store['status'] ?? 'active') === 'active';
        $token = trim((string) ($store['domain_verification_token'] ?? ''));

        $proofDetail = match ((string) ($store['domain_verification_method'] ?? '')) {
            'txt' => 'Proved by a TXT record.',
            'cname' => 'Proved by the domain pointing at us.',
            'manual' => 'Forced by an administrator — DNS was not checked.',
            default => 'Verified before we started recording the method.',
        };

        return [
            [
                'key' => 'domain_claimed',
                'label' => 'Domain claimed',
                'done' => $domain !== '',
                'manual' => false,
                'detail' => $domain !== '' ? $domain : 'No custom domain has been claimed yet.',
            ],
            [
                'key' => 'dns_proof',
                'label' => 'Control of the domain proved',
                'done' => $verified,
                'manual' => false,
                'detail' => $verified
                    ? $proofDetail
                    : ($domain === '' || $token === ''
                        ? 'Claim a domain first.'
                        : 'Add a TXT record at ' . $this->verifier->recordName($domain)
                            . ' containing ' . $this->verifier->expectedValue($token)
                            . ', or point ' . $domain . ' at ' . $this->locator->platformHost() . '.'),
            ],
            [
                'key' => 'store_active',
                'label' => 'Store switched on',
                'done' => $active,
                'manual' => false,
                'detail' => $active
                    ? 'The store is active.'
                    : 'The store is suspended — its domain returns 503 rather than our shop.',
            ],
            [
                'key' => 'dns_points_here',
                'label' => 'Domain added on the server and pointed here',
                'done' => null,
                'manual' => true,
                // Naming BOTH halves matters. "Point the DNS at us" reads as
                // sufficient and is not: a record that resolves here does not make
                // the web server ANSWER for the hostname. Until the domain is added
                // to the server too, the server falls back to its default vhost and
                // the browser shows an error or somebody else's page.
                'detail' => 'On the server, and outside this application: add '
                    . ($domain !== '' ? $domain : 'the domain')
                    . ' to the web server that runs this platform (cPanel: Domains → Create A New Domain, with the'
                    . ' same document root as the platform), AND point its DNS here — an A record to this server\'s'
                    . ' IP, or a CNAME to ' . $this->locator->platformHost() . '.',
            ],
            [
                'key' => 'tls_certificate',
                'label' => 'TLS certificate issued',
                'done' => null,
                'manual' => true,
                'detail' => 'On the server: issue a certificate for ' . ($domain !== '' ? $domain : 'the domain')
                    . ' once it has been added and its DNS resolves (cPanel: SSL/TLS Status → Run AutoSSL),'
                    . ' or browsers will refuse the connection.',
            ],
        ];
    }

    /**
     * The store-wide markup, clamped by the rule's owner so there is one
     * definition of what a legal markup is (ResellerRetailPricing).
     */
    public function setMarkup(int $storeId, float $percent): void
    {
        $this->stores->setMarkup($storeId, ResellerRetailPricing::clampMarkup($percent));
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
     * The store's free web address (`{slug}.{store domain}`), the one that works
     * before the reseller sets up a domain of their own. Built from APP_URL's scheme,
     * never from the request, so it is the same string everywhere it is shown.
     * Null when the super admin has not set a platform address domain: then the store
     * is served only on its own verified domain.
     *
     * @param array<string, mixed> $store
     */
    public function platformUrl(array $store): ?string
    {
        $address = $this->locator->platformAddressFor($store);

        return $address === null ? null : $this->locator->platformScheme() . '://' . $address;
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
