<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use Throwable;

/**
 * The "Free Reseller" programme: the public face of the reseller system.
 *
 * Nothing here changes HOW reselling works — stores, pricing, the ledger and
 * payouts all keep their own owners. This class holds the few things that are
 * about ADVERTISING the programme (is it open, where is it advertised, which
 * demo stores to show, what the landing page says) and reads the live
 * programme rules from their real owners so the public page can never promise
 * a holding period or a payout minimum the admin has since changed.
 *
 * Main host only, by rule: every advert, banner and page belonging to this
 * programme is the PLATFORM's marketing and must never appear on a reseller's
 * store (see shouldAdvertiseTo()).
 */
final class FreeResellerProgramme
{
    public const KEY_ENABLED = 'free_reseller.enabled';
    public const KEY_CLIENT_ADVERT = 'free_reseller.client_advert';
    public const KEY_PUBLIC_BANNER = 'free_reseller.public_banner';
    public const KEY_DEMO_LINKS = 'free_reseller.demo_links';
    public const KEY_HEADLINE = 'free_reseller.headline';
    public const KEY_TAGLINE = 'free_reseller.tagline';
    public const KEY_BANNER_TEXT = 'free_reseller.banner_text';

    /** A guest's half-finished application, kept while they create an account or sign in. */
    public const DRAFT_SESSION_KEY = 'free_reseller_draft';

    public const MAX_DEMO_LINKS = 6;
    public const HEADLINE_MAX = 120;
    public const TAGLINE_MAX = 300;
    public const BANNER_TEXT_MAX = 140;

    public const DEFAULT_HEADLINE = 'Start your own hosting business — free.';
    public const DEFAULT_TAGLINE = 'Get a ready-made, fully branded hosting website. Sell our VPS, dedicated servers, domains and hosting at your own prices, and keep the profit. We run the servers and handle the support while you grow.';
    public const DEFAULT_BANNER_TEXT = 'Get a FREE reseller website — sell hosting, VPS & domains under your own brand.';

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->flag(self::KEY_ENABLED);
    }

    /** The card on a direct client's dashboard. */
    public function clientAdvertEnabled(): bool
    {
        return $this->enabled() && $this->flag(self::KEY_CLIENT_ADVERT);
    }

    /** The strip across the main website's public pages and the home page section. */
    public function publicBannerEnabled(): bool
    {
        return $this->enabled() && $this->flag(self::KEY_PUBLIC_BANNER);
    }

    public function headline(): string
    {
        return $this->text(self::KEY_HEADLINE, self::DEFAULT_HEADLINE);
    }

    public function tagline(): string
    {
        return $this->text(self::KEY_TAGLINE, self::DEFAULT_TAGLINE);
    }

    public function bannerText(): string
    {
        return $this->text(self::KEY_BANNER_TEXT, self::DEFAULT_BANNER_TEXT);
    }

    /**
     * The demo stores the super admin wants prospects to see.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function demoLinks(): array
    {
        $decoded = json_decode((string) $this->settings->get(self::KEY_DEMO_LINKS, '[]'), true);

        if (!is_array($decoded)) {
            return [];
        }

        $labels = [];
        $urls = [];

        foreach ($decoded as $row) {
            if (is_array($row)) {
                $labels[] = (string) ($row['label'] ?? '');
                $urls[] = (string) ($row['url'] ?? '');
            }
        }

        return self::normaliseDemoLinks($labels, $urls)['links'];
    }

    /**
     * Save everything the admin page edits in one go.
     *
     * @param array<int, mixed> $demoLabels
     * @param array<int, mixed> $demoUrls
     * @return array{success: bool, errors: array<int, string>}
     */
    public function save(
        bool $enabled,
        bool $clientAdvert,
        bool $publicBanner,
        string $headline,
        string $tagline,
        string $bannerText,
        array $demoLabels,
        array $demoUrls
    ): array {
        $links = self::normaliseDemoLinks($demoLabels, $demoUrls);

        $this->settings->set(self::KEY_ENABLED, $enabled ? '1' : '0');
        $this->settings->set(self::KEY_CLIENT_ADVERT, $clientAdvert ? '1' : '0');
        $this->settings->set(self::KEY_PUBLIC_BANNER, $publicBanner ? '1' : '0');
        $this->settings->set(self::KEY_HEADLINE, self::clip($headline, self::HEADLINE_MAX));
        $this->settings->set(self::KEY_TAGLINE, self::clip($tagline, self::TAGLINE_MAX));
        $this->settings->set(self::KEY_BANNER_TEXT, self::clip($bannerText, self::BANNER_TEXT_MAX));
        $this->settings->set(self::KEY_DEMO_LINKS, (string) json_encode($links['links'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['success' => $links['errors'] === [], 'errors' => $links['errors']];
    }

    /**
     * Clean the demo-link rows the admin typed: blank rows are dropped, a URL must
     * be http(s) (it is rendered as a link on a public page, so `javascript:` and
     * friends are refused), a missing label falls back to the host, and at most
     * MAX_DEMO_LINKS are kept.
     *
     * @param array<int, mixed> $labels
     * @param array<int, mixed> $urls
     * @return array{links: array<int, array{label: string, url: string}>, errors: array<int, string>}
     */
    public static function normaliseDemoLinks(array $labels, array $urls): array
    {
        $links = [];
        $errors = [];
        $labels = array_values($labels);
        $urls = array_values($urls);

        foreach ($urls as $i => $rawUrl) {
            $url = trim(is_scalar($rawUrl) ? (string) $rawUrl : '');
            $label = self::clip(is_scalar($labels[$i] ?? null) ? (string) $labels[$i] : '', 60);

            if ($url === '') {
                if ($label !== '') {
                    $errors[] = 'Demo "' . $label . '" has no web address, so it was not saved.';
                }

                continue;
            }

            if (!preg_match('~^https?://~i', $url)) {
                $url = 'https://' . $url;
            }

            $host = parse_url($url, PHP_URL_HOST);

            if (filter_var($url, FILTER_VALIDATE_URL) === false || !is_string($host) || !str_contains($host, '.')) {
                $errors[] = '"' . $url . '" is not a valid web address, so it was not saved.';
                continue;
            }

            if (count($links) >= self::MAX_DEMO_LINKS) {
                $errors[] = 'Only ' . self::MAX_DEMO_LINKS . ' demo links are kept; the rest were dropped.';
                break;
            }

            $links[] = ['label' => $label !== '' ? $label : strtolower($host), 'url' => $url];
        }

        return ['links' => $links, 'errors' => $errors];
    }

    /**
     * Whether the programme may be advertised to this visitor.
     *
     * - Never on a reseller's store: that site belongs to another business, and the
     *   platform's marketing on it would break white-labelling.
     * - Guests on the main website: yes.
     * - A signed-in client: only a DIRECT platform client (no reseller_id) who does
     *   not already run a store. A store's customer is that store's customer, and
     *   is never recruited from under it.
     *
     * @param array<string, mixed>|null $client
     * @param array<string, mixed>|null $storefront the store this host belongs to, if any
     */
    public static function shouldAdvertiseTo(?array $client, ?array $storefront, bool $ownsStore): bool
    {
        if ($storefront !== null) {
            return false;
        }

        if ($client === null) {
            return true;
        }

        if ((int) ($client['reseller_id'] ?? 0) > 0) {
            return false;
        }

        return !$ownsStore;
    }

    public const PLACEMENT_NAV = 'nav';
    public const PLACEMENT_PUBLIC = 'public';
    public const PLACEMENT_CLIENT = 'client';

    /**
     * What a view needs to show one of the programme's adverts on THIS request, or
     * null when that advert must not appear. One decision for every placement (the
     * header link, the public strip, the home page section, the dashboard card), so
     * they cannot disagree about who is recruited:
     *
     * - nav: the programme is open;
     * - public: the programme is open and the public banner is switched on;
     * - client: the programme is open and the dashboard advert is switched on;
     *
     * and in every case shouldAdvertiseTo() agrees (main website, not a store's
     * customer, not already a reseller). Any failure resolving it means no advert:
     * marketing must never be the reason a page breaks.
     *
     * @return array{headline: string, tagline: string, text: string, client: ?array<string, mixed>}|null
     */
    public static function advertFor(string $placement): ?array
    {
        try {
            $container = App::container();
            $programme = $container->make(self::class);

            $on = match ($placement) {
                self::PLACEMENT_CLIENT => $programme->clientAdvertEnabled(),
                self::PLACEMENT_PUBLIC => $programme->publicBannerEnabled(),
                default => $programme->enabled(),
            };

            if (!$on) {
                return null;
            }

            $storefront = $container->make(CurrentReseller::class)->get();

            if ($storefront !== null) {
                return null;
            }

            $client = $container->make(ClientAuthGuard::class)->currentClient();
            $ownsStore = $client !== null
                && $container->make(ResellerStoreRepository::class)->forClient((int) $client['id']) !== null;

            if (!self::shouldAdvertiseTo($client, $storefront, $ownsStore)) {
                return null;
            }

            return [
                'headline' => $programme->headline(),
                'tagline' => $programme->tagline(),
                'text' => $programme->bannerText(),
                'client' => $client,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The worked example on the landing page: what a reseller earns per sale.
     *
     * Same formula as the real pricing engine for a first-tier store: cost is the
     * list price less the reseller discount, retail is the list price plus the
     * reseller's markup (capped like ResellerRetailPricing caps it).
     *
     * @return array{list: float, cost: float, retail: float, profit: float}
     */
    public static function earningsExample(float $listPrice, float $discountPercent, float $markupPercent): array
    {
        $list = max(0.0, $listPrice);
        $discount = ResellerSettings::clampPercent($discountPercent);
        $markup = ResellerRetailPricing::clampMarkup($markupPercent);
        $cost = round($list * (1 - $discount / 100), 2);
        $retail = round($list * (1 + $markup / 100), 2);

        return ['list' => round($list, 2), 'cost' => $cost, 'retail' => $retail, 'profit' => round($retail - $cost, 2)];
    }

    /** A switch exactly as saved (default on), regardless of whether the programme is open. */
    public function switchOn(string $key): bool
    {
        return $this->flag($key);
    }

    private function flag(string $key): bool
    {
        return (string) $this->settings->get($key, '1') === '1';
    }

    private function text(string $key, string $default): string
    {
        $value = trim((string) $this->settings->get($key, ''));

        return $value !== '' ? $value : $default;
    }

    private static function clip(string $value, int $max): string
    {
        $value = trim((string) preg_replace('~\s+~u', ' ', $value));

        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
