<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * The reseller area's navigation.
 *
 * No database and no container: the partial only uses `e()` and the request path, so it can
 * be rendered directly. That matters because the interesting behaviour here is the ACTIVE
 * state, and that is decided by the path — so it needs to be exercised from several paths,
 * which a real request would make awkward.
 *
 * The active item used to be something each controller would have had to pass in, and the
 * failure would have been silent: a page rendering with nothing highlighted, and nobody
 * noticing. Deriving it from the path cannot drift.
 */
final class ResellerNavTest extends TestCase
{
    private View $view;
    private ?string $originalUri;

    protected function setUp(): void
    {
        parent::setUp();
        $this->view = new View(dirname(__DIR__, 2) . '/resources/views');
        $this->originalUri = $_SERVER['REQUEST_URI'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->originalUri;
        }

        parent::tearDown();
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function navAt(string $path): array
    {
        $_SERVER['REQUEST_URI'] = $path;

        $diagnostics = [];
        set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        try {
            $html = $this->view->render('partials.reseller-nav');
        } finally {
            restore_error_handler();
        }

        return [$html, $diagnostics];
    }

    /** The href of the one item marked active. */
    private function activeHref(string $html): string
    {
        $pos = strpos($html, 'rs-nav__link--active');
        $this->assertNotFalse($pos, 'no item is marked active');

        // The href follows the class on the next line for the active item.
        $window = substr($html, $pos, 300);
        $this->assertSame(1, preg_match('/href="([^"]+)"/', $window, $m), 'the active item has no href');

        return $m[1];
    }

    // ------------------------------------------------------------------ shape ---

    public function test_it_renders_every_destination(): void
    {
        [$html, $diagnostics] = $this->navAt('/client/reseller');

        $this->assertSame([], $diagnostics);

        foreach ([
            '/client/reseller',
            '/client/reseller/store',
            '/client/reseller/prices',
            '/client/reseller/promotions',
            '/client/reseller/tickets',
            '/client/reseller/migrations',
            '/client/reseller/mail',
            '/client/reseller/account',
            '/client/reseller/statements',
            '/client/reseller/docs',
        ] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $html, "the nav lost {$href}");
        }
    }

    public function test_it_carries_its_own_stylesheet(): void
    {
        // Emitted by the partial rather than by each view, so a new page cannot forget it
        // and the portal cannot end up half-styled.
        [$html] = $this->navAt('/client/reseller');

        $this->assertStringContainsString('/assets/css/reseller.css', $html);
    }

    // ------------------------------------------------------------ active state ---

    public function test_exactly_one_item_is_active_on_every_page(): void
    {
        // The overview is a PREFIX of every other path, so the obvious implementation lights
        // up BOTH it and the current section on every page — which is why the count is
        // asserted rather than just the presence.
        foreach ([
            '/client/reseller',
            '/client/reseller/store',
            '/client/reseller/account',
            '/client/reseller/statements',
            '/client/reseller/migrations',
        ] as $path) {
            [$html] = $this->navAt($path);

            $this->assertSame(1, substr_count($html, 'rs-nav__link--active'), "more or less than one active item at {$path}");
            $this->assertSame(1, substr_count($html, 'aria-current="page"'), "aria-current is wrong at {$path}");
        }
    }

    public function test_the_overview_is_active_only_on_the_reseller_home(): void
    {
        [$home] = $this->navAt('/client/reseller');
        $this->assertSame('/client/reseller', $this->activeHref($home));

        // The case that would break with a naive prefix match.
        [$store] = $this->navAt('/client/reseller/store');
        $this->assertSame('/client/reseller/store', $this->activeHref($store));
    }

    public function test_a_child_path_highlights_its_section(): void
    {
        // /client/reseller/statements/12 must highlight Statements rather than nothing —
        // which is what an exact-match-only implementation would do.
        [$html] = $this->navAt('/client/reseller/statements/12');

        $this->assertSame('/client/reseller/statements', $this->activeHref($html));
    }

    public function test_a_trailing_slash_does_not_lose_the_highlight(): void
    {
        [$html] = $this->navAt('/client/reseller/account/');

        $this->assertSame('/client/reseller/account', $this->activeHref($html));
    }

    public function test_it_renders_without_diagnostics_when_there_is_no_request(): void
    {
        // A CLI context — a cron mail render, or any test — has no REQUEST_URI. Reading it
        // unguarded would warn, and a warning printed before a header() breaks a redirect.
        $diagnostics = [];

        set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        try {
            $html = $this->view->render('partials.reseller-nav');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $diagnostics, 'the nav must render without a request');
        $this->assertSame(0, substr_count($html, 'aria-current'), 'with no path, nothing should claim to be current');
    }

    // -------------------------------------------------------- no page is naked ---

    public function test_every_reseller_page_includes_the_nav(): void
    {
        // The failure this prevents is the one that produced this partial: a page that simply
        // has no links back, so the section is unreachable from wherever it was forgotten.
        $directory = dirname(__DIR__, 2) . '/resources/views/reseller';
        $checked = 0;

        foreach ((array) glob($directory . '/client-*.php') as $file) {
            $file = (string) $file;

            // client-statement-document is a printable document, deliberately chrome-free.
            if (str_contains($file, 'client-statement-document')) {
                continue;
            }

            $source = (string) file_get_contents($file);

            $this->assertStringContainsString(
                'partials.reseller-nav',
                $source,
                basename($file) . ' does not include the reseller nav, so that page has no way back'
            );

            $checked++;
        }

        // Eleven client-* views exist and one of them (the statement document) is deliberately
        // chrome-free, so ten is the real number (the Customers list and customer page are
        // the newest two). Asserted rather than assumed: a glob that silently matches nothing
        // would make this test pass while checking no page at all — which is exactly the
        // vacuous-pass shape this suite exists to avoid.
        $this->assertSame(10, $checked, 'expected ten navigable reseller pages; the glob is not seeing them all');
    }
}
