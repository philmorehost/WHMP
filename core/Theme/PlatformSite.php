<?php

declare(strict_types=1);

namespace CodeVault\Theme;

use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;

/**
 * The MAIN website's public design — the platform's own premium hosting look.
 *
 * The main host now wears the same premium chrome a reseller's store does
 * (partials/storefront-header, -page-banner, -footer and storefront.css), so
 * every public page — home, store, product, cart, domains, deals, knowledgebase,
 * sign-in — looks like one professional hosting brand instead of a plain client
 * area with a home page bolted on.
 *
 * What stays strictly apart:
 *
 *  - The platform's own extras (its company contact details, Knowledgebase,
 *    network status, affiliate scheme, Free Reseller advert, guarantees) are only
 *    rendered when the layout passes `platform => true`, and the layout only does
 *    that in its MAIN-WEBSITE branch — a host that matched a store never gets
 *    them, whatever the settings say.
 *
 *  - The look can be switched back to the classic layout from Admin → Theme
 *    (site.design = classic), as a safety valve; stores are unaffected either
 *    way because they always use the storefront chrome.
 *
 * Everything here is read through SettingsRepository; the statics resolve it
 * from the container so the shared layout/partials can ask without every public
 * controller gaining a constructor dependency.
 */
final class PlatformSite
{
    public const DESIGN_KEY = 'site.design';
    public const HEADLINE_KEY = 'site.home_headline';
    public const TAGLINE_KEY = 'site.home_tagline';
    public const TRUST_KEY = 'site.trust_line';

    public const DESIGN_PREMIUM = 'premium';
    public const DESIGN_CLASSIC = 'classic';

    public const DEFAULT_HEADLINE = 'Premium hosting, VPS & domains that just work';

    public const DEFAULT_TAGLINE = 'Blazing-fast web hosting, VPS and dedicated servers, domains and business email — '
        . 'with expert support around the clock and everything managed from one simple client area.';

    public const DEFAULT_TRUST = 'Learn why businesses and resellers trust us with their websites.';

    /** How many categories the home page's pricing tabs offer, and plans per tab. */
    public const FEATURED_GROUPS = 6;

    public const PLANS_PER_GROUP = 3;

    /** The design a stored value selects. Anything but "classic" is the premium look. */
    public static function premiumFor(?string $design): bool
    {
        return strtolower(trim((string) $design)) !== self::DESIGN_CLASSIC;
    }

    /**
     * Whether the main website uses the premium design on THIS request.
     *
     * False whenever the request belongs to a store (a store has its own chrome)
     * and whenever there is no container or no settings table yet — installer,
     * CLI, a view rendered in isolation by a test — so nothing that used to
     * render the classic chrome changes underneath it.
     */
    public static function premiumEnabled(): bool
    {
        try {
            $container = App::container();

            if ($container->make(\CodeVault\Reseller\CurrentReseller::class)->get() !== null) {
                return false;
            }

            return self::premiumFor($container->make(SettingsRepository::class)->get(self::DESIGN_KEY, self::DESIGN_PREMIUM));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function headlineFor(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : self::DEFAULT_HEADLINE;
    }

    public static function taglineFor(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : self::DEFAULT_TAGLINE;
    }

    public static function trustFor(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : self::DEFAULT_TRUST;
    }

    /**
     * The platform company's public contact details, from Admin → Settings →
     * Company. Only ever read for the MAIN website's chrome.
     *
     * @return array{email: string, phone: string, whatsapp: string, address: string}
     */
    public static function contact(?SettingsRepository $settings = null): array
    {
        $read = static function (string $key) use ($settings): string {
            try {
                $repo = $settings ?? App::container()->make(SettingsRepository::class);

                return trim((string) ($repo->get($key, '') ?? ''));
            } catch (\Throwable) {
                return '';
            }
        };

        return [
            'email' => $read('company.email'),
            'phone' => $read('company.phone'),
            'whatsapp' => self::digits($read('company.whatsapp')),
            'address' => $read('company.address'),
        ];
    }

    /** A phone number as the digits wa.me wants, or '' when it is not one. */
    public static function digits(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : '';
    }

    /** One home-page setting, or null when settings cannot be read. */
    public static function setting(string $key): ?string
    {
        try {
            return App::container()->make(SettingsRepository::class)->get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The guarantees band on the home page: icon, title, one honest sentence.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function guarantees(): array
    {
        return [
            ['headset', '24/7 expert support', 'Real engineers on hand day and night — open a ticket or chat with us whenever you need help.'],
            ['rocket', 'Fast & reliable', 'Modern servers and SSD storage keep your websites quick and your applications responsive.'],
            ['panel', 'Super easy to use', 'Order, pay, manage and renew every service from one clean, simple client area.'],
            ['shield', 'Uptime you can count on', 'Monitored infrastructure designed to keep your websites online around the clock.'],
            ['lock', 'Secure servers', 'Hardened servers, firewalls and free SSL options help keep your data and visitors safe.'],
            ['wallet', 'Money-back guarantee', 'Try us with confidence. If we are not the right fit, talk to us about a refund.'],
            ['bolt', 'High performance', 'Generous resources and optimised stacks so busy websites stay fast under load.'],
            ['globe', 'Domains & email included', 'Register domains and set up professional email right alongside your hosting.'],
        ];
    }
}
