<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Reseller\StorefrontHome;
use CodeVault\Reseller\StorefrontIcons;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * The reseller website's front page and the chrome every storefront page shares.
 *
 * The promises pinned here:
 *
 *  1. The front page stays short. Categories are reached through the SERVICES
 *     menu, so the home page never prints a card per category or every plan —
 *     only a few featured plans, one tab at a time.
 *  2. Every category with something to buy is in the SERVICES dropdown, linked
 *     to its own page, in both the desktop menu and the phone drawer.
 *  3. Nothing on these pages is the platform's: no hard-coded colours, no
 *     platform-only links (affiliate programme, platform terms).
 *  4. Plan descriptions typed one feature per line become a ticked list, with
 *     whatever bullet characters the admin typed stripped.
 */
final class StorefrontHomeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    // ------------------------------------------------------------ features ---

    public function test_a_description_with_one_feature_per_line_becomes_a_list(): void
    {
        $this->assertSame(
            ['10 GB NVMe storage', 'Free SSL certificate', 'cPanel'],
            StorefrontHome::features("- 10 GB NVMe storage\n• Free SSL certificate\r\n\n✓ cPanel")
        );
    }

    public function test_html_line_breaks_and_list_items_split_features_too(): void
    {
        $this->assertSame(
            ['5 websites', 'Daily backups & restores'],
            StorefrontHome::features('<ul><li>5 websites</li><li>Daily backups &amp; restores</li></ul>')
        );
        $this->assertSame(['One', 'Two'], StorefrontHome::features('One<br>Two'));
    }

    public function test_a_single_sentence_is_not_a_list(): void
    {
        $this->assertSame([], StorefrontHome::features('Great hosting for small business websites.'));
        $this->assertSame([], StorefrontHome::features('   '));
    }

    public function test_the_feature_list_is_capped(): void
    {
        $this->assertCount(3, StorefrontHome::features("a\nb\nc\nd\ne", 3));
    }

    // -------------------------------------------------------- home page copy ---

    public function test_a_store_without_its_own_copy_gets_the_defaults(): void
    {
        $this->assertSame(StorefrontHome::DEFAULT_HEADLINE, StorefrontHome::headlineFor([]));
        $this->assertSame(StorefrontHome::DEFAULT_TAGLINE, StorefrontHome::taglineFor(['home_tagline' => '   ']));
    }

    public function test_a_store_with_its_own_copy_keeps_it(): void
    {
        $store = ['home_headline' => '  Hosting made in Lagos ', 'home_tagline' => 'Local support, local prices.'];

        $this->assertSame('Hosting made in Lagos', StorefrontHome::headlineFor($store));
        $this->assertSame('Local support, local prices.', StorefrontHome::taglineFor($store));
    }

    // --------------------------------------------------------------- icons ---

    public function test_categories_map_to_sensible_icons(): void
    {
        $this->assertSame('mail', StorefrontIcons::forCategory('Business Email'));
        $this->assertSame('globe', StorefrontIcons::forCategory('Domain Names'));
        $this->assertSame('shield', StorefrontIcons::forCategory('SSL Certificates'));
        $this->assertSame('box', StorefrontIcons::forCategory('Something Else Entirely'));
    }

    public function test_icons_are_inline_svg_that_take_the_text_colour(): void
    {
        $svg = StorefrontIcons::svg('server');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('currentColor', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        // An unknown name falls back to a neutral icon rather than a hole in the layout.
        $this->assertSame(StorefrontIcons::svg('box'), StorefrontIcons::svg('no-such-icon'));
    }

    // -------------------------------------------------------------- header ---

    public function test_every_category_is_in_the_services_dropdown(): void
    {
        $html = $this->render('partials.storefront-header', ['categories' => $this->categories()]);

        $this->assertMatchesRegularExpression('/>\s*Services\s*</', $html, 'The categories dropdown must be labelled Services.');

        foreach ($this->categories() as $category) {
            // Once in the desktop mega menu, once in the phone drawer.
            $this->assertSame(
                2,
                substr_count($html, 'href="/store?group_id=' . $category['id'] . '"'),
                "{$category['name']} must be linked from the menu and the drawer."
            );
        }

        $this->assertStringContainsString('href="/store"', $html, 'The menu must offer the full catalogue.');
    }

    public function test_the_header_carries_no_platform_links_or_colours(): void
    {
        $html = $this->render('partials.storefront-header', ['categories' => $this->categories()])
            . $this->render('partials.storefront-footer', ['categories' => $this->categories()]);

        $this->assertStringNotContainsString('/client/affiliate', $html);
        $this->assertStringNotContainsString('/terms', $html);
        $this->assertStringNotContainsString('ResellerClub', $html);
        $this->assertSame(0, preg_match('/(?<!&)#[0-9a-fA-F]{3,8}\b/', $html), 'Storefront chrome must take the store\'s colours.');
    }

    public function test_a_store_with_nothing_for_sale_shows_no_empty_dropdown(): void
    {
        $html = $this->render('partials.storefront-header', ['categories' => []]);

        $this->assertStringNotContainsString('group_id=', $html);
        $this->assertStringContainsString('href="/store"', $html);
    }

    // ----------------------------------------------------------- home page ---

    public function test_the_home_page_does_not_list_every_category(): void
    {
        $categories = $this->categories();
        $featured = [$categories[0] + ['plans' => [
            ['id' => 101, 'name' => 'Starter', 'description' => "1 website\nFree SSL", 'starting_price' => 1500.0, 'starting_cycle' => 'monthly'],
        ]]];

        $html = $this->render('storefront.home', [
            'headline' => 'Hosting made in Lagos',
            'tagline' => StorefrontHome::DEFAULT_TAGLINE,
            'categories' => $categories,
            'featured' => $featured,
            'domainPrices' => [['tld' => '.com', 'price' => 15000.0]],
            'tldCount' => 12,
        ]);

        $this->assertStringContainsString('Hosting made in Lagos', $html);
        $this->assertStringContainsString('Starter', $html, 'Featured plans are shown.');
        $this->assertStringContainsString('/store/101', $html);
        $this->assertStringContainsString('.com', $html);
        // The two categories that were not featured must not appear as their own
        // cards: they are one click away under SERVICES instead.
        $this->assertStringNotContainsString('Business Email', $html);
        $this->assertStringNotContainsString('group_id=3', $html);
        $this->assertSame(0, preg_match('/(?<!&)#[0-9a-fA-F]{3,8}\b/', $html));
    }

    public function test_the_home_page_renders_with_an_empty_catalogue(): void
    {
        $html = $this->render('storefront.home', []);

        $this->assertStringContainsString(StorefrontHome::DEFAULT_HEADLINE, $html);
        $this->assertStringNotContainsString('id="plans"', $html, 'No plans, no pricing section.');
    }

    // ------------------------------------------------------------- helpers ---

    /** @return array<int, array<string, mixed>> */
    private function categories(): array
    {
        return [
            ['id' => 1, 'name' => 'Shared Hosting', 'description' => 'cPanel hosting.', 'product_count' => 3, 'starting_price' => 1500.0, 'starting_cycle' => 'monthly'],
            ['id' => 2, 'name' => 'VPS Servers', 'description' => '', 'product_count' => 2, 'starting_price' => 12000.0, 'starting_cycle' => 'monthly'],
            ['id' => 3, 'name' => 'Business Email', 'description' => '', 'product_count' => 1, 'starting_price' => null, 'starting_cycle' => null],
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
                'store' => ['id' => 9, 'brand_name' => 'Lagos Cloud Host', 'support_email' => 'help@example.test'],
                'theme' => ['brandName' => 'Lagos Cloud Host', 'logoUrl' => null],
                'money' => static fn (float $amount): string => 'NGN ' . number_format($amount, 2),
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
