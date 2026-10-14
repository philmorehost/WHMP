<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Settings\SettingsRepository;

/**
 * Whose name an outgoing email carries — and making sure, when it is a store's, that
 * nothing in it is ours.
 *
 * WHY THIS LIVES BEHIND THE DISPATCHER RATHER THAN AT EVERY CALL SITE
 *
 * There are about fifty places that send a templated email, and most of them pass no
 * store at all — many do not even pass the client id. Teaching each one to brand its
 * mail would be fifty chances to forget, and every forgotten one is a store's customer
 * receiving a message signed by the platform, from the platform's address, with links
 * to the platform's site. So EmailDispatcher asks this class once per message, and the
 * call sites stay as they are.
 *
 * WHO A MESSAGE IS FOR (storeFor), in order:
 *
 *   1. A client id was given        -> the store that owns that client.
 *   2. The recipient is one of OUR admins -> the platform. Staff mail is ours.
 *   3. The recipient is a client's address -> the store that owns that client.
 *   4. We are serving a store's site right now -> that store. This is the
 *      registration code sent before the account exists, and a guest's ticket.
 *   5. Otherwise                    -> the platform.
 *
 * An unclaimed platform account (no history: see ClientSiteAccess) dealing with a store
 * is written to as that store, because that is the site they are using.
 *
 * WHAT CHANGES ON A STORE'S MESSAGE
 *
 *   - From name and address: the store's (ResellerMailIdentity::senderFor).
 *   - {{company_name}} / {{brand_name}}: the store's name.
 *   - Links to our site: rewritten to the store's own address.
 *   - Our name, our bare hostname and addresses at our domain, wherever they appear in
 *     the rendered text — an admin may have typed them into a template by hand.
 *   - The email shell: the store's name, logo and colour (EmailDispatcher).
 *
 * WHAT DOES NOT, and cannot: the SMTP server's own hostname (EHLO and the Received
 * headers its server adds). That name has to match the server's reverse DNS or the
 * message is treated as spam — it identifies the machine, not the sender, and every
 * shared host on the internet works this way. Real server hostnames in hosting details
 * (a control panel address, nameservers) are left alone too, because rewriting them
 * would break the service they describe; the rewrite deliberately skips any host that
 * is a SUBDOMAIN of ours or carries a port.
 */
final class StoreMailBranding
{
    /**
     * Words too generic to replace even if somebody set them as the brand name —
     * rewriting every "Support" in a message to a store's name would be worse than
     * the leak it closes.
     */
    private const GENERIC_NAMES = ['support', 'billing', 'hosting', 'admin', 'team', 'sales', 'help', 'system'];

    public function __construct(
        private readonly Database $db,
        private readonly ResellerStoreRepository $stores,
        private readonly ResellerStoreLocator $locator,
        private readonly CurrentReseller $current,
        private readonly ClientSiteAccess $access,
        private readonly ?SettingsRepository $settings = null,
        private readonly ?Config $config = null
    ) {
    }

    /**
     * The store a message is written on behalf of, or null for the platform.
     *
     * @return array<string, mixed>|null
     */
    public function storeFor(string $toEmail, ?int $clientId): ?array
    {
        if ($clientId !== null && $clientId > 0) {
            $client = $this->db->selectOne('SELECT id, reseller_id FROM clients WHERE id = ? LIMIT 1', [$clientId]);

            if ($client !== null) {
                return $this->storeForClient($client);
            }
        }

        $email = strtolower(trim($toEmail));

        if ($email !== '') {
            if ($this->db->selectOne('SELECT id FROM admins WHERE LOWER(email) = ? LIMIT 1', [$email]) !== null) {
                return null;
            }

            $client = $this->db->selectOne('SELECT id, reseller_id FROM clients WHERE email = ? LIMIT 1', [$email]);

            if ($client !== null) {
                return $this->storeForClient($client);
            }
        }

        return $this->current->get();
    }

    /**
     * Everything a store's message needs: who it is from, where its links go, and
     * what of ours has to be taken out.
     *
     * @param array<string, mixed> $store
     * @return array{
     *     store_id: int, name: string, email: string, url: string, host: string,
     *     color: string, logo: ?string,
     *     platform_url: string, platform_host: string, platform_names: array<int, string>
     * }
     */
    public function contextFor(array $store): array
    {
        $host = $this->locator->hostFor($store);
        $url = $this->locator->baseUrlFor($store);
        $sender = ResellerMailIdentity::senderFor($store, $host);

        return [
            'store_id' => (int) ($store['id'] ?? 0),
            'name' => $sender['name'],
            'email' => $sender['email'],
            'url' => $url,
            'host' => $host,
            'color' => self::safeColor((string) ($store['primary_color'] ?? '')),
            'logo' => self::absoluteLogo((string) ($store['logo_url'] ?? ''), $url),
            'platform_url' => rtrim((string) ($this->config?->env('APP_URL', '') ?? ''), '/'),
            'platform_host' => $this->locator->platformHost(),
            'platform_names' => $this->platformNames($sender['name']),
        ];
    }

    /**
     * The template variables that NAME the sender, set to the store.
     *
     * Callers pass the platform's name as {{company_name}} almost everywhere
     * (brand_name() at the time of the call, often a cron job with no store in
     * sight), so it is overwritten here rather than trusted.
     *
     * @param array<string, string> $variables
     * @param array{name: string} $context
     * @return array<string, string>
     */
    public static function brandVariables(array $variables, array $context): array
    {
        foreach (['company_name', 'brand_name', 'site_name', 'app_name'] as $key) {
            $variables[$key] = $context['name'];
        }

        return $variables;
    }

    /**
     * Take every trace of the platform out of a rendered subject or body.
     *
     * ONE regular expression and ONE pass, deliberately: replacing the URL, then the
     * host, then the name in separate passes would re-process text the earlier pass
     * had just inserted — a store called "PhilmoreHost Resellers Ltd" would have its
     * own name rewritten, and a store address at our subdomain would have its host
     * mangled. A single pass only ever looks at the original text.
     *
     * The four things it recognises, tried in this order at each position:
     *
     *   url    http(s)://[www.]OUR-HOST (exact authority, no other port) -> store URL.
     *          The path after it is kept, so /client/invoices/12 still works.
     *   email  anything@[www.]OUR-HOST                                   -> store's sender.
     *   host   a bare OUR-HOST written as text                           -> store host.
     *   name   our brand name, as a whole word                           -> store name.
     *
     * The guards on host and name are what keep real infrastructure intact: a host
     * PRECEDED by a dot (ns1.our-host, server1.our-host) is a subdomain and is skipped;
     * one FOLLOWED by a dot and more label (our-host.ng when we are our-host) is a
     * different domain and is skipped; a name inside an address or a path is skipped.
     *
     * @param array{
     *     name: string, email: string, url: string, host: string,
     *     platform_url: string, platform_host: string, platform_names: array<int, string>
     * } $context
     */
    public static function rebrand(string $text, array $context, bool $html): string
    {
        if ($text === '') {
            return $text;
        }

        $alternatives = [];
        $platformHost = strtolower(trim($context['platform_host']));
        // A host with no dot (localhost) is too likely to be an ordinary word in text,
        // so only the full URL form is rewritten for it.
        $hostIsDomain = $platformHost !== '' && str_contains($platformHost, '.');

        if ($platformHost !== '') {
            $quotedHost = preg_quote($platformHost, '~');
            $port = (string) (parse_url($context['platform_url'], PHP_URL_PORT) ?? '');
            $portPart = $port !== '' ? ':' . preg_quote($port, '~') : '';

            $alternatives[] = '(?P<url>https?://(?:www\.)?' . $quotedHost . $portPart . ')(?=[/"\'<>\s?#)\]]|$)';

            if ($hostIsDomain) {
                $alternatives[] = '(?P<email>(?<![\w.%+-])[A-Za-z0-9._%+-]+@(?:www\.)?' . $quotedHost . ')(?![\w-]|\.[A-Za-z0-9])';
                $alternatives[] = '(?P<host>(?<![\w.@/-])(?:www\.)?' . $quotedHost . ')(?![\w-]|\.[A-Za-z0-9])';
            }
        }

        $names = array_values(array_filter(
            $context['platform_names'],
            static fn (string $name): bool => $name !== ''
        ));

        if ($names !== []) {
            // Longest first, so "PhilmoreHost Cloud" wins over "PhilmoreHost".
            usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $quoted = array_map(static fn (string $name): string => preg_quote($name, '~'), $names);
            $alternatives[] = '(?P<name>(?<![\w.@/-])(?:' . implode('|', $quoted) . '))(?![\w@-]|\.[A-Za-z0-9])';
        }

        if ($alternatives === []) {
            return $text;
        }

        $storeName = $html ? htmlspecialchars($context['name'], ENT_QUOTES, 'UTF-8') : $context['name'];

        $result = preg_replace_callback(
            '~' . implode('|', $alternatives) . '~iu',
            static function (array $m) use ($context, $storeName): string {
                if (($m['url'] ?? '') !== '') {
                    return $context['url'];
                }

                if (($m['email'] ?? '') !== '') {
                    return $context['email'];
                }

                if (($m['host'] ?? '') !== '') {
                    return $context['host'];
                }

                return $storeName;
            },
            $text
        );

        // A backtracking failure leaves the text as it was rather than empty: an
        // un-rebranded message is a flaw, a blank one is a lost message.
        return $result ?? $text;
    }

    // ------------------------------------------------------------- internals ---

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>|null
     */
    private function storeForClient(array $client): ?array
    {
        $owner = (int) ($client['reseller_id'] ?? 0);

        if ($owner > 0) {
            return $this->stores->find($owner);
        }

        // An unclaimed account using a store's site is that store's prospect.
        $site = $this->current->get();

        if ($site !== null && $this->access->isUnclaimed($client)) {
            return $site;
        }

        return null;
    }

    /**
     * Every name the platform goes by, minus anything too generic to replace and
     * minus the store's own name (replacing a word with itself is harmless, but a
     * store named after a word in ours must not have that word stripped).
     *
     * @return array<int, string>
     */
    private function platformNames(string $storeName): array
    {
        $candidates = [
            (string) ($this->settings?->get('theme.brand_name', '') ?? ''),
            (string) ($this->config?->get('app.name') ?? ''),
            (string) ($this->config?->env('APP_NAME', '') ?? ''),
            // The product's own name: the default brand of an unconfigured install,
            // and not something a store's customer should ever read either.
            'CodeVault',
        ];

        $names = [];

        foreach ($candidates as $name) {
            $name = trim($name);

            if (mb_strlen($name) < 3 || in_array(strtolower($name), self::GENERIC_NAMES, true)) {
                continue;
            }

            if (strcasecmp($name, trim($storeName)) === 0) {
                continue;
            }

            $names[strtolower($name)] = $name;
        }

        return array_values($names);
    }

    private static function safeColor(string $color): string
    {
        $color = trim($color);

        return preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $color) === 1 ? $color : '#2563eb';
    }

    /**
     * A logo usable from an inbox: an absolute http(s) URL, or a site-relative path
     * made absolute against the STORE's address (a relative path in an email resolves
     * against nothing).
     */
    private static function absoluteLogo(string $logo, string $storeUrl): ?string
    {
        $logo = trim($logo);

        if ($logo === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $logo) === 1) {
            return $logo;
        }

        return str_starts_with($logo, '/') ? rtrim($storeUrl, '/') . $logo : null;
    }
}
