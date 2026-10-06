<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Support\App;
use CodeVault\Theme\PlatformSite;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * The MAIN website's premium design (PlatformSite / PlatformHome).
 *
 * The promises pinned here:
 *
 *  1. The platform's own extras — company contact details, Knowledgebase,
 *     network status, Affiliates, the Free Reseller link — appear ONLY when the
 *     chrome is rendered in platform mode, which layouts/client.php does only in
 *     its main-website branch. A store's header and footer never carry them, even
 *     for a signed-in customer.
 *  2. The home page lists the catalogue as a compact services grid with live
 *     prices, the guarantees and the Free Reseller section (when advertised), and
 *     writes no colour of its own — everything follows Admin → Theme.
 *  3. "classic" is the only value that switches the premium design off.
 */
final class PlatformSiteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $_SERVER['REQUEST_URI'] = '/';

        $config = new Config(sys_get_temp_dir() . '/codevault-platform-noenv-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        App::setContainer($container);
    }

    // ------------------------------------------------------------- settings ---

    public function test_only_classic_switches_the_premium_design_off(): void
    {
        $this->assertTrue(PlatformSite::premiumFor(null));
        $this->assertTrue(PlatformSite::premiumFor(''));
        $this->assertTrue(PlatformSite::premiumFor('premium'));
        $this->assertFalse(PlatformSite::premiumFor('classic'));
        $this->assertFalse(PlatformSite::premiumFor(' Classic '));
    }

    public function test_empty_home_page_words_fall_back_to_the_defaults(): void
    {
        $this->assertSame(PlatformSite::DEFAULT_HEADLINE, PlatformSite::headlineFor('  '));
        $this->assertSame('Hosting from Lagos', PlatformSite::headlineFor(' Hosting from Lagos '));
        $this->assertSame(PlatformSite::DEFAULT_TAGLINE, PlatformSite::taglineFor(null));
        $this->assertSame(PlatformSite::DEFAULT_TRUST, PlatformSite::trustFor(''));
    }

    public function test_whatsapp_digits_are_cleaned_and_validated(): void
    {
        $this->assertSame('2348012345678', PlatformSite::digits('+234 801 234 5678'));
        $this->assertSame('', PlatformSite::digits('call us'));
        $this->assertSame('', PlatformSite::digits('12345'));
    }

    public function test_every_guarantee_has_an_icon_a_title_and_a_sentence(): void
    {
        $guarantees = PlatformSite::guarantees();

        $this->assertCount(8, $guarantees);

        foreach ($guarantees as [$icon, $title, $text]) {
            $this->assertNotSame('', $icon);
            $this->assertNotSame('', $title);
            $this->assertStringEndsWithPeriod($text);
        }
    }

    // --------------------------------------------------------------- chrome ---

    public function test_platform_mode_adds_the_platform_extras_to_the_header(): void
    {
        $html = $this->render('partials.storefront-header', [
            'platform' => true,
            'contact' => ['email' => 'support@platform.test', 'phone' => '', 'whatsapp' => '2348012345678', 'address' => ''],
            'freeResellerNav' => true,
        ]);

        $this->assertStringContainsString('support@platform.test', $html);
        $this->assertStringContainsString('https://wa.me/2348012345678', $html);
        $this->assertStringContainsString('href="/kb"', $html);
        $this->assertStringContainsString('href="/status"', $html);
        $this->assertStringContainsString('href="/free-reseller"', $html);
    }

    public function test_a_store_header_never_carries_platform_extras_even_for_a_signed_in_customer(): void
    {
        $html = $this->render('partials.storefront-header', [
            'store' => ['id' => 9, 'brand_name' => 'Lagos Cloud Host', 'support_email' => 'help@store.test'],
            'client' => ['id' => 5, 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.test'],
            'unreadNotifications' => 2,
            // Even if something asked for it, a store is not in platform mode.
            'freeResellerNav' => true,
        ]);

        $this->assertStringContainsString('help@store.test', $html);
        $this->assertStringContainsString('Ada Obi', $html, 'The account menu names the customer.');
        $this->assertStringContainsString('href="/client/invoices"', $html);
        $this->assertStringContainsString('action="/client/logout"', $html);
        $this->assertStringNotContainsString('/client/affiliate', $html);
        $this->assertStringNotContainsString('href="/kb"', $html);
        $this->assertStringNotContainsString('href="/status"', $html);
        $this->assertStringNotContainsString('/free-reseller', $html);
        $this->assertSame(0, preg_match('/(?<!&)#[0-9a-fA-F]{3,8}\b/', $html));
    }

    public function test_the_platform_account_menu_offers_affiliates(): void
    {
        $html = $this->render('partials.storefront-header', [
            'platform' => true,
            'contact' => ['email' => '', 'phone' => '', 'whatsapp' => '', 'address' => ''],
            'freeResellerNav' => false,
            'client' => ['id' => 5, 'first_name' => 'Ada', 'last_name' => '', 'email' => 'ada@example.test'],
            'unreadNotifications' => 0,
        ]);

        $this->assertStringContainsString('href="/client/affiliate"', $html);
        $this->assertStringNotContainsString('href="/free-reseller"', $html, 'The Free Reseller link follows the programme setting.');
    }

    public function test_platform_footer_has_company_pages_and_a_store_footer_does_not(): void
    {
        $platform = $this->render('partials.storefront-footer', [
            'platform' => true,
            'contact' => ['email' => 'support@platform.test', 'phone' => '+234 1 234 5678', 'whatsapp' => '', 'address' => '1 Marina, Lagos'],
            'theme' => ['brandName' => 'Philmore Host', 'termsUrl' => 'https://platform.test/terms'],
        ]);
        $store = $this->render('partials.storefront-footer', [
            'store' => ['id' => 9, 'brand_name' => 'Lagos Cloud Host', 'support_email' => 'help@store.test'],
        ]);

        $this->assertStringContainsString('href="/client/affiliate"', $platform);
        $this->assertStringContainsString('href="https://platform.test/terms"', $platform);
        $this->assertStringContainsString('1 Marina, Lagos', $platform);
        $this->assertStringContainsString('tel:+23412345678', $platform);

        $this->assertStringNotContainsString('/client/affiliate', $store);
        $this->assertStringNotContainsString('href="/kb"', $store);
        $this->assertStringNotContainsString('support@platform.test', $store);
    }

    // ------------------------------------------------------------ home page ---

    public function test_the_home_page_shows_services_plans_guarantees_and_the_reseller_offer(): void
    {
        $html = $this->render('pages.home-premium', [
            'headline' => 'Hosting from Lagos',
            'categories' => $this->categories(),
            'featured' => [$this->categories()[0] + ['plans' => [
                ['id' => 101, 'name' => 'Starter', 'description' => "1 website\nFree SSL", 'starting_price' => 1500.0, 'starting_cycle' => 'monthly'],
            ]]],
            'domainPrices' => [['tld' => '.com', 'price' => 15000.0]],
            'tldCount' => 40,
            'contact' => ['email' => 'support@platform.test', 'whatsapp' => '2348012345678'],
            'freeReseller' => ['headline' => 'Start your own hosting business', 'tagline' => 'Free branded website.'],
        ]);

        $this->assertStringContainsString('Hosting from Lagos', $html);

        foreach ($this->categories() as $category) {
            $this->assertStringContainsString('href="/store?group_id=' . $category['id'] . '"', $html, "{$category['name']} has a card in the services grid.");
        }

        $this->assertStringContainsString('From <b>NGN 1,500.00</b>/mo', $html);
        $this->assertStringContainsString('/store/101', $html);
        $this->assertStringContainsString('24/7 expert support', $html);
        $this->assertStringContainsString('Start your own hosting business', $html);
        $this->assertStringContainsString('href="/free-reseller"', $html);
        $this->assertStringContainsString('https://wa.me/2348012345678', $html);
        $this->assertSame(0, preg_match('/(?<!&)#[0-9a-fA-F]{3,8}\b/', $html), 'The home page must follow the theme colour.');
    }

    public function test_the_home_page_renders_with_an_empty_catalogue_and_no_programme(): void
    {
        $html = $this->render('pages.home-premium', ['freeReseller' => [], 'categories' => []]);

        $this->assertStringContainsString(e(PlatformSite::DEFAULT_HEADLINE), $html);
        $this->assertStringNotContainsString('id="plans"', $html);
        $this->assertStringNotContainsString('id="services"', $html);
        $this->assertStringNotContainsString('/free-reseller', $html);
    }

    public function test_the_premium_deals_page_never_links_the_affiliate_scheme(): void
    {
        $html = $this->render('pages.deals', [
            'premium' => true,
            'promotions' => [
                ['code' => 'SAVE20', 'type' => 'percentage', 'value' => '20.00', 'expires_at' => '2026-12-31'],
                ['code' => 'TENOFF', 'type' => 'fixed', 'value' => '10.00', 'expires_at' => null],
            ],
        ]);

        $this->assertStringContainsString('data-sf-copy="SAVE20"', $html);
        $this->assertStringContainsString('20%', $html);
        $this->assertStringContainsString('NGN 10.00', $html, 'A fixed discount is shown in the visitor\'s currency.');
        $this->assertStringNotContainsString('/client/affiliate', $html);
        $this->assertStringNotContainsString('home-sidebar', $html);
    }

    public function test_the_stylesheet_uses_only_tokens_that_exist(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($this->root . '/public/assets/css/platform.css'));
        $tokens = (string) file_get_contents($this->root . '/public/assets/css/tokens.css');
        $storefront = (string) file_get_contents($this->root . '/public/assets/css/storefront.css');

        preg_match_all('/^\s*(--cv-[a-z0-9-]+)\s*:/m', $tokens, $defined);
        preg_match_all('/var\((--cv-[a-z0-9-]+)/', $css, $used);
        preg_match_all('/(--sf-[a-z0-9-]+)\s*:/', $storefront, $sfDefined);
        preg_match_all('/var\((--sf-[a-z0-9-]+)/', $css, $sfUsed);
        preg_match_all('/--cv-color-brand-(\d+)/', $css, $steps);

        $this->assertSame([], array_values(array_diff(array_unique($used[1]), $defined[1])));
        $this->assertSame([], array_values(array_diff(array_unique($sfUsed[1]), $sfDefined[1])));
        $this->assertSame([], array_values(array_diff(array_unique($steps[1]), ['500', '600'])), 'Only the brand steps Admin → Theme sets.');
        $this->assertSame(substr_count($css, '{'), substr_count($css, '}'));
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }

    // -------------------------------------------------------------- helpers ---

    private function assertStringEndsWithPeriod(string $text): void
    {
        $this->assertMatchesRegularExpression('/\.$/', $text);
    }

    /** @return array<int, array<string, mixed>> */
    private function categories(): array
    {
        return [
            ['id' => 1, 'name' => 'Shared Hosting', 'description' => 'cPanel hosting.', 'product_count' => 3, 'starting_price' => 1500.0, 'starting_cycle' => 'monthly'],
            ['id' => 2, 'name' => 'VPS SSD', 'description' => '', 'product_count' => 2, 'starting_price' => 12000.0, 'starting_cycle' => 'monthly'],
            ['id' => 3, 'name' => 'Email Hosting', 'description' => '', 'product_count' => 1, 'starting_price' => null, 'starting_cycle' => null],
        ];
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): string
    {
        $diagnostics = [];

        set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        try {
            $html = (new View($this->root . '/resources/views'))->render($template, $data + [
                'theme' => ['brandName' => 'Philmore Host', 'logoUrl' => null],
                'money' => static fn (float $amount): string => 'NGN ' . number_format($amount, 2),
                'categories' => $this->categories(),
                'client' => null,
                'cartCount' => 0,
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $diagnostics, "{$template} must render without diagnostics.");

        return $html;
    }
}
