<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Localization\Translation;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * The storefront (/store) is the FRONT PAGE of a reseller's store and is served
 * on the reseller's own domain, so it carries two promises that are easy to
 * break and impossible to notice on a platform-branded test install:
 *
 *  1. It must not show the platform's colours. The active store's accent
 *     arrives as --cv-color-brand-500/600 (see layouts/client.php). The version
 *     this replaces carried 38 literal hex values and used the token twice, so a
 *     reseller who chose red still got the platform's navy-and-gold.
 *
 *  2. Only steps 500 and 600 are tenant-overridden. Every other step (-50,
 *     -100, ...) still describes the PLATFORM palette, so using one for a tinted
 *     surface silently reintroduces exactly the leak, in a way that looks like a
 *     harmless bit of polish. That is what
 *     test_the_stylesheet_only_uses_brand_steps_a_tenant_can_override guards.
 *
 * Nothing here touches the database: it renders the view and reads the
 * stylesheet as text, so it cannot collide with the shared codevault_test
 * database.
 */
final class StorefrontLayoutTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    // ------------------------------------------------------------- helpers ---

    /**
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<int, string>} rendered HTML and any PHP diagnostics
     */
    private function renderStorefront(array $data): array
    {
        $diagnostics = [];

        set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        try {
            $html = (new View($this->root . '/resources/views'))->render('cart.store', $data + [
                // get() returns the KEY when a string is missing, so these must be
                // supplied explicitly or the page would print "store.no_products".
                't' => new Translation(['code' => 'en', 'direction' => 'ltr'], [
                    'store.no_products' => 'No products are available just now.',
                    'store.no_products_in_group' => 'No plans in this group just now.',
                ]),
                'money' => static fn (float $amount): string => '$' . number_format($amount, 2),
                // The main website's premium design is a site setting; pin it off so
                // these tests see the classic catalogue whatever an earlier test left
                // in the shared container.
                'platformPremium' => false,
            ]);
        } finally {
            restore_error_handler();
        }

        return [$html, $diagnostics];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupWithProducts(): array
    {
        return [[
            'id' => 7,
            'name' => 'Shared Hosting',
            'description' => 'Fast, reliable shared plans.',
            'products' => [[
                'id' => 21,
                'name' => 'Business Starter',
                'description' => "10GB SSD storage\nFree SSL",
                'starting_price' => 12.5,
                'starting_cycle' => 'monthly',
            ]],
        ]];
    }

    // ------------------------------------------------------------ rendering ---

    public function test_the_storefront_renders_a_group_and_its_plans_without_diagnostics(): void
    {
        [$html, $diagnostics] = $this->renderStorefront([
            'groups' => $this->groupWithProducts(),
            'theme' => ['brandName' => 'Philmore Host', 'logoUrl' => null],
        ]);

        $this->assertSame([], $diagnostics, 'The storefront must not raise diagnostics while rendering.');
        $this->assertStringContainsString('Philmore Host', $html, 'The store must show the reseller brand, not the platform.');
        $this->assertStringContainsString('/assets/css/store.css', $html, 'The storefront stylesheet must be linked.');
        $this->assertStringContainsString('store-grid', $html);
        $this->assertStringContainsString('store-card', $html);
        $this->assertStringContainsString('Shared Hosting', $html);
        $this->assertStringContainsString('$12.50', $html, 'The retail price must reach the card.');
        $this->assertStringContainsString('/store/21', $html, 'The card must link to its own product page.');
    }

    public function test_a_product_with_no_description_still_renders_a_price_and_a_link(): void
    {
        // An empty description is normal for a freshly imported product, and an
        // empty heading block would leave the card looking broken.
        [$html, $diagnostics] = $this->renderStorefront([
            'groups' => [[
                'id' => 3,
                'name' => 'Domains',
                'description' => '',
                'products' => [[
                    'id' => 44,
                    'name' => 'Register a domain',
                    'description' => '   ',
                    'starting_price' => 9.0,
                    'starting_cycle' => 'yearly',
                ]],
            ]],
        ]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('$9.00', $html);
        $this->assertStringContainsString('Yearly', $html, 'The billing cycle must be shown in words.');
        $this->assertStringNotContainsString('store-card__description', $html, 'A blank description must not emit an empty block.');
    }

    public function test_a_store_with_nothing_for_sale_shows_one_empty_state_and_no_grid(): void
    {
        [$html, $diagnostics] = $this->renderStorefront(['groups' => []]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('store-empty', $html);
        $this->assertStringContainsString('No products are available just now.', $html);
        $this->assertStringNotContainsString('store-grid', $html, 'With no catalogue there is nothing to lay out.');
        $this->assertStringNotContainsString('#plans', $html, 'Nothing to scroll to, so the hero must not offer to scroll there.');
    }

    public function test_a_group_with_no_plans_is_skipped_rather_than_shown_empty(): void
    {
        // A customer-facing catalogue should not advertise a category with
        // nothing in it. Skipping is also what makes the hero's quick links
        // honest: the same list drives both, so a link can never point at a
        // heading that renders as an empty state.
        [$html, $diagnostics] = $this->renderStorefront([
            'groups' => [[
                'id' => 12,
                'name' => 'Seasonal Offers',
                'description' => 'Back soon.',
                'products' => [],
            ]],
        ]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('store-empty', $html);
        $this->assertStringContainsString('No products are available just now.', $html);
        $this->assertStringNotContainsString('store-grid', $html);
        $this->assertStringNotContainsString('store-card', $html);
        $this->assertStringNotContainsString('Seasonal Offers', $html);
        $this->assertStringNotContainsString('store-hero__aside', $html, 'Nothing to navigate to.');
    }

    public function test_the_hero_quick_links_only_name_groups_that_have_plans(): void
    {
        $groups = $this->groupWithProducts();
        $groups[] = [
            'id' => 99,
            'name' => 'Empty Group',
            'description' => '',
            'products' => [],
        ];

        [$html, $diagnostics] = $this->renderStorefront(['groups' => $groups]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('group-7', $html);
        $this->assertStringContainsString('store-hero__aside', $html);
        $this->assertStringNotContainsString('group-99', $html);
        $this->assertStringNotContainsString('Empty Group', $html);
    }

    public function test_the_storefront_renders_when_no_theme_was_injected(): void
    {
        // View::render() only adds 'theme' when it was built with a ThemeSettings,
        // and reading an offset off an undefined variable is a warning — which,
        // before a header(), turns a missing brand name into a broken page.
        [$html, $diagnostics] = $this->renderStorefront(['groups' => $this->groupWithProducts()]);

        $this->assertSame([], $diagnostics, 'A missing theme must not produce diagnostics.');
        $this->assertStringContainsString('store-hero__title', $html);
    }

    // ------------------------------------------------------------ white label ---

    public function test_the_storefront_view_carries_no_hard_coded_colour(): void
    {
        $view = (string) file_get_contents($this->root . '/resources/views/cart/store.php');

        // The negative lookbehind skips numeric HTML entities (&#8595;), which are
        // characters, not colours. A check that cries wolf is worse than no check.
        preg_match_all('/(?<!&)#[0-9a-fA-F]{3,8}\b/', $view, $matches);

        $this->assertSame([], $matches[0], 'Brand colour must come from the tenant tokens, not from the view.');
    }

    public function test_the_storefront_shows_no_platform_or_upstream_vendor_branding(): void
    {
        [$html, $diagnostics] = $this->renderStorefront([
            'groups' => $this->groupWithProducts(),
            'theme' => ['brandName' => 'Philmore Host'],
        ]);

        $this->assertSame([], $diagnostics);

        // Asserted against what a visitor receives, not against the view's source:
        // the source legitimately NAMES the removed vendor, in a comment saying why
        // it is gone. Grepping the file for it would flag the explanation.
        $this->assertStringNotContainsString('Premium Hosting Solutions', $html, 'The platform headline must not appear on a reseller storefront.');
        $this->assertStringNotContainsString('ResellerClub', $html, 'The upstream vendor must not appear on a reseller storefront.');
    }

    public function test_the_stylesheet_only_uses_brand_steps_a_tenant_can_override(): void
    {
        $css = (string) file_get_contents($this->root . '/public/assets/css/store.css');

        // Comments are stripped first: this stylesheet deliberately NAMES the
        // platform-only steps in a comment explaining why they must not be used,
        // and a scan that reads prose as usage flags the explanation itself.
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        preg_match_all('/--cv-color-brand-(\d+)/', $css, $matches);
        $steps = array_values(array_unique($matches[1]));

        $this->assertNotSame([], $steps, 'The storefront must actually use the tenant accent.');

        // Only 500 and 600 are overridden per store; every other step would show
        // the platform's tint while looking like ordinary polish.
        foreach ($steps as $step) {
            $this->assertContains(
                $step,
                ['500', '600'],
                "store.css uses --cv-color-brand-{$step}, which no tenant overrides, so it would show the platform's colour."
            );
        }
    }

    public function test_every_design_token_the_stylesheet_uses_actually_exists(): void
    {
        $css = (string) file_get_contents($this->root . '/public/assets/css/store.css');
        $tokens = (string) file_get_contents($this->root . '/public/assets/css/tokens.css');

        // Comments are stripped so a token named in prose is not read as a use.
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        preg_match_all('/^\s*(--cv-[a-z0-9-]+)\s*:/m', $tokens, $defined);
        preg_match_all('/var\((--cv-[a-z0-9-]+)/', $css, $used);

        $missing = array_values(array_diff(array_unique($used[1]), array_unique($defined[1])));

        // A misspelt custom property is not an error in CSS: it silently resolves
        // to nothing, so the rule simply stops applying with no clue why.
        $this->assertSame([], $missing, 'store.css references tokens that tokens.css does not define.');
    }

    public function test_the_stylesheet_is_balanced_and_responsive(): void
    {
        $css = (string) file_get_contents($this->root . '/public/assets/css/store.css');

        // One missing brace silently discards every rule after it.
        $this->assertSame(
            substr_count($css, '{'),
            substr_count($css, '}'),
            'store.css has an unbalanced brace, which silently drops the rules that follow it.'
        );

        // The storefront it replaces had a single media query for 449 lines.
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($css, '@media'),
            'The storefront must carry real breakpoints, not one cramped override.'
        );
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }
}
