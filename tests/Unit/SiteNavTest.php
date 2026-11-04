<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Support\App;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * The main website's navigation — the header bar and the footer.
 *
 * Rendered directly, the way ResellerNavTest renders the reseller nav: the
 * header resolves its product categories, notifications and currency switcher
 * through the container behind try/catch blocks, so a container holding only
 * an inert (never-connected) Database is enough to render it with all of
 * those quietly empty. What matters here is the LINKS: the public API
 * documentation page was buried one controller deep, and a nav link that
 * silently disappears is exactly the failure nobody notices — so it is
 * asserted from the rendered HTML, not from the file's source.
 */
final class SiteNavTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        parent::setUp();

        $this->view = new View(dirname(__DIR__, 2) . '/resources/views');

        // The header makes Database the one UNGUARDED container lookup; an
        // instance whose connection is never opened keeps the render offline.
        $container = new Container();
        $container->instance(Database::class, new Database('127.0.0.1', '1', 'site-nav-test', 'nobody', 'secret'));
        App::setContainer($container);

        $_SESSION = [];
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function render(string $template): array
    {
        $diagnostics = [];
        set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        try {
            $html = $this->view->render($template);
        } finally {
            restore_error_handler();
        }

        return [$html, $diagnostics];
    }

    public function test_the_main_menu_links_the_api_documentation(): void
    {
        [$html, $diagnostics] = $this->render('partials.header');

        // Once in the desktop bar, once in the mobile menu — losing either
        // silently drops the page for half the visitors.
        $this->assertSame(
            2,
            substr_count($html, 'href="/client/reseller/docs"'),
            'the API docs link must appear in BOTH the desktop nav and the mobile nav'
        );

        // The link was added, not swapped in: the original menu survives.
        foreach (['/client/dashboard', '/store', '/domains/register', '/client/invoices', '/client/tickets'] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $html, "the nav lost {$href}");
        }

        $this->assertSame([], $diagnostics);
    }

    public function test_the_footer_menu_links_the_api_documentation(): void
    {
        [$html, $diagnostics] = $this->render('partials.footer');

        foreach (['/store', '/domains/register', '/client/tickets', '/client/reseller/docs'] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $html, "the footer menu lost {$href}");
        }

        $this->assertSame([], $diagnostics);
    }

    // --- the premium chrome (the main website's default look) --------------

    /**
     * layouts/client.php has TWO chromes: the classic header/footer above, and
     * the premium storefront chrome (Admin → Theme → Website design, ON by
     * default) — which is the pair the main website actually wears. The API
     * link must live in BOTH chromes; the first two tests alone let it vanish
     * from the site the moment the premium chrome is on.
     *
     * @return array<string, mixed>
     */
    private function storefrontHeaderData(bool $platform): array
    {
        return [
            'store' => [],
            'home' => false,
            'currencies' => [],
            'selectedCurrency' => null,
            't' => null,
            'languages' => null,
            'theme' => ['brandName' => 'TestBrand'],
            'categories' => [],
            'client' => null,
            'cartCount' => 0,
            'money' => null,
            'platform' => $platform,
            'contact' => ['email' => 'help@example.test', 'whatsapp' => '', 'phone' => ''],
            'unreadNotifications' => 0,
            'freeResellerNav' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function storefrontFooterData(bool $platform): array
    {
        return [
            'store' => [],
            'theme' => ['brandName' => 'TestBrand'],
            't' => null,
            'categories' => [],
            'platform' => $platform,
            'contact' => ['email' => 'help@example.test', 'whatsapp' => '', 'phone' => '', 'address' => ''],
        ];
    }

    public function test_the_premium_main_menu_links_the_api_documentation(): void
    {
        [$html, $diagnostics] = $this->renderStorefrontHeader(true);

        // Desktop nav AND the mobile drawer — same both-places rule as above.
        $this->assertSame(2, substr_count($html, 'href="/client/reseller/docs"'));
        $this->assertSame([], $diagnostics);
    }

    public function test_the_premium_chrome_hides_the_api_link_on_a_reseller_store(): void
    {
        // A store's website is the reseller's own brand: the platform's API is
        // not theirs to advertise (the standing white-label rule), so with
        // platform => false the link must not render AT ALL.
        [$header, $headerDiagnostics] = $this->renderStorefrontHeader(false);
        [$footer, $footerDiagnostics] = $this->renderStorefrontFooter(false);

        $this->assertStringNotContainsString('/client/reseller/docs', $header);
        $this->assertStringNotContainsString('/client/reseller/docs', $footer);
        $this->assertSame([], $headerDiagnostics);
        $this->assertSame([], $footerDiagnostics);
    }

    public function test_the_premium_footer_links_the_api_documentation(): void
    {
        [$html, $diagnostics] = $this->renderStorefrontFooter(true);

        $this->assertStringContainsString('href="/client/reseller/docs"', $html);
        $this->assertStringContainsString('API Documentation', $html);
        $this->assertSame([], $diagnostics);
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function renderStorefrontHeader(bool $platform): array
    {
        $html = $this->view->render('partials.storefront-header', $this->storefrontHeaderData($platform));

        return [$html, []];
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function renderStorefrontFooter(bool $platform): array
    {
        $html = $this->view->render('partials.storefront-footer', $this->storefrontFooterData($platform));

        return [$html, []];
    }
}
