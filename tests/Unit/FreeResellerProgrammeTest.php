<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Reseller\FreeResellerProgramme;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Support\App;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * The Free Reseller programme: who is advertised to, the demo-link rules, the
 * earnings example, and that every page renders the LIVE programme rules it is
 * given (never a hard-coded "30 days").
 */
final class FreeResellerProgrammeTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        parent::setUp();
        $this->view = new View(dirname(__DIR__, 2) . '/resources/views');
        $_SERVER['REQUEST_URI'] = '/free-reseller';

        $config = new Config(sys_get_temp_dir() . '/codevault-fr-noenv-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        App::setContainer($container);

        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $no, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        unset($_SESSION[FreeResellerProgramme::DRAFT_SESSION_KEY]);
        parent::tearDown();
    }

    // --- rules -------------------------------------------------------------

    public function test_demo_links_are_cleaned_and_unsafe_ones_refused(): void
    {
        $result = FreeResellerProgramme::normaliseDemoLinks(
            ['Hosting demo', '', 'Bad', 'No URL', ''],
            ['demo.example.com', 'https://shop.example.org/path', 'javascript:alert(1)', '', '']
        );

        $this->assertSame([
            ['label' => 'Hosting demo', 'url' => 'https://demo.example.com'],
            ['label' => 'shop.example.org', 'url' => 'https://shop.example.org/path'],
        ], $result['links']);
        $this->assertCount(2, $result['errors']);
    }

    public function test_at_most_six_demo_links_are_kept(): void
    {
        $urls = [];

        for ($i = 1; $i <= 8; $i++) {
            $urls[] = 'https://demo' . $i . '.example.com';
        }

        $result = FreeResellerProgramme::normaliseDemoLinks([], $urls);

        $this->assertCount(FreeResellerProgramme::MAX_DEMO_LINKS, $result['links']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_adverts_never_reach_a_store_a_store_customer_or_an_existing_reseller(): void
    {
        $store = ['id' => 5, 'slug' => 'acme'];

        // Guests and direct clients on the main website: yes.
        $this->assertTrue(FreeResellerProgramme::shouldAdvertiseTo(null, null, false));
        $this->assertTrue(FreeResellerProgramme::shouldAdvertiseTo(['id' => 1, 'reseller_id' => null], null, false));

        // Anything on a reseller's own website: never.
        $this->assertFalse(FreeResellerProgramme::shouldAdvertiseTo(null, $store, false));
        $this->assertFalse(FreeResellerProgramme::shouldAdvertiseTo(['id' => 1, 'reseller_id' => null], $store, false));

        // A store's customer is that store's, and is not recruited from under it.
        $this->assertFalse(FreeResellerProgramme::shouldAdvertiseTo(['id' => 2, 'reseller_id' => 5], null, false));

        // Already a reseller.
        $this->assertFalse(FreeResellerProgramme::shouldAdvertiseTo(['id' => 1, 'reseller_id' => null], null, true));
    }

    public function test_the_earnings_example_uses_the_real_pricing_formula(): void
    {
        $this->assertSame(
            ['list' => 10.0, 'cost' => 8.0, 'retail' => 13.0, 'profit' => 5.0],
            FreeResellerProgramme::earningsExample(10.0, 20.0, 30.0)
        );

        // Markup is capped exactly as the retail engine caps it.
        $capped = FreeResellerProgramme::earningsExample(10.0, 0.0, 5000.0);
        $this->assertSame(110.0, $capped['retail']);
    }

    public function test_an_advert_that_cannot_be_resolved_is_simply_not_shown(): void
    {
        // The bare test container has no database: the advert must quietly be off.
        $this->assertNull(FreeResellerProgramme::advertFor(FreeResellerProgramme::PLACEMENT_PUBLIC));
    }

    // --- pages -------------------------------------------------------------

    public function test_the_landing_page_quotes_the_live_programme_rules(): void
    {
        $html = $this->view->render('free-reseller.landing', $this->landingData());

        $this->assertStringContainsString('14-day hold', $html);
        $this->assertStringContainsString('5,000.00 NGN', $html);
        $this->assertStringContainsString('once a week', $html);
        $this->assertStringContainsString('less 15%', $html);
        $this->assertStringContainsString('yourname.stores.example.com', $html);
        $this->assertStringContainsString('_codevault-verify.yourdomain.com', $html);
        $this->assertStringContainsString('VPS Hosting', $html);
        $this->assertStringContainsString('Master reseller', $html);
        $this->assertStringContainsString('href="/free-reseller/apply"', $html);
        $this->assertStringNotContainsString('30-day', $html);
    }

    public function test_the_landing_page_escapes_the_admins_text_and_demo_links(): void
    {
        $data = $this->landingData();
        $data['headline'] = 'Free <script>x</script> websites';
        $data['demoLinks'] = [['label' => 'Demo <b>1</b>', 'url' => 'https://demo.example.com/?a=1&b=2']];

        $html = $this->view->render('free-reseller.landing', $data);

        $this->assertStringContainsString('<em>Free</em> &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('Demo &lt;b&gt;1&lt;/b&gt;', $html);
        $this->assertStringContainsString('href="https://demo.example.com/?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('id="demos"', $html);
    }

    public function test_a_closed_programme_shows_a_notice_instead_of_the_offer(): void
    {
        $data = $this->landingData();
        $data['enabled'] = false;

        $html = $this->view->render('free-reseller.landing', $data);

        $this->assertStringContainsString('not open right now', $html);
        $this->assertStringNotContainsString('/free-reseller/apply', $html);
    }

    public function test_an_existing_reseller_is_sent_to_their_area_not_the_form(): void
    {
        $data = $this->landingData();
        $data['ownsStore'] = true;

        $html = $this->view->render('free-reseller.landing', $data);

        $this->assertStringContainsString('Open your Reseller Area', $html);
        $this->assertStringNotContainsString('href="/free-reseller/apply"', $html);
    }

    public function test_the_form_asks_a_guest_to_create_an_account_or_sign_in(): void
    {
        $html = $this->view->render('free-reseller.apply', $this->formData(null));

        $this->assertStringContainsString('name="account_action" value="register"', $html);
        $this->assertStringContainsString('name="account_action" value="login"', $html);
        $this->assertStringContainsString('data-fr-domain-input', $html);
        $this->assertStringContainsString('name="existing_domain"', $html);
        $this->assertStringContainsString('.stores.example.com', $html);
        $this->assertStringContainsString('.com<span>', $html);
    }

    public function test_the_form_shows_a_signed_in_client_and_their_errors(): void
    {
        $data = $this->formData(['id' => 42, 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.test']);
        $data['values']['domain_mode'] = 'existing';
        $data['errors'] = ['existing_domain' => 'mybrand.com is already used by another reseller store.'];

        $html = $this->view->render('free-reseller.apply', $data);

        $this->assertStringContainsString('Client ID #42', $html);
        $this->assertStringContainsString('Create my reseller website', $html);
        $this->assertStringNotContainsString('name="account_action"', $html);
        $this->assertStringContainsString('already used by another reseller store', $html);
        $this->assertMatchesRegularExpression('~value="existing"\s+checked~', $html);
    }

    public function test_the_welcome_page_gives_the_exact_dns_records_for_an_existing_domain(): void
    {
        $html = $this->view->render('free-reseller.welcome', $this->welcomeData('existing', false));

        $this->assertStringContainsString('Reseller ID #9', $html);
        $this->assertStringContainsString('_codevault-verify.mybrand.com', $html);
        $this->assertStringContainsString('codevault-verify=abc123', $html);
        $this->assertStringContainsString('platform.example.com', $html);
        $this->assertStringContainsString('href="/client/reseller/store"', $html);
    }

    public function test_the_welcome_page_sends_a_new_domain_to_checkout(): void
    {
        $html = $this->view->render('free-reseller.welcome', $this->welcomeData('register', true));

        $this->assertStringContainsString('Pay for mybrand.com', $html);
        $this->assertStringContainsString('href="/cart"', $html);
    }

    public function test_the_admin_page_offers_six_demo_rows_and_the_switches(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/resellers/free-programme';

        $html = $this->view->render('reseller.admin-free-programme', [
            'enabled' => true,
            'clientAdvert' => true,
            'publicBanner' => false,
            'headline' => FreeResellerProgramme::DEFAULT_HEADLINE,
            'tagline' => FreeResellerProgramme::DEFAULT_TAGLINE,
            'bannerText' => FreeResellerProgramme::DEFAULT_BANNER_TEXT,
            'demoLinks' => [['label' => 'Demo one', 'url' => 'https://one.example.com']],
            'stats' => ['stores' => 4, 'last30' => 2, 'pendingDomains' => 1],
            'rules' => ['serviceDiscount' => 15.0, 'domainDiscount' => 5.0, 'holdingDays' => 14, 'payoutMinimum' => '10.00 USD', 'storeDomain' => null],
            'notice' => null,
            'error' => null,
        ]);

        $this->assertSame(FreeResellerProgramme::MAX_DEMO_LINKS, substr_count($html, 'name="demo_url[]"'));
        $this->assertStringContainsString('value="https://one.example.com"', $html);
        $this->assertMatchesRegularExpression('~name="enabled" value="1" checked~', $html);
        $this->assertMatchesRegularExpression('~name="public_banner" value="1" >~', $html);
        $this->assertStringContainsString('OPEN TO APPLICATIONS', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    public function test_the_sign_in_pages_mention_a_saved_application(): void
    {
        $_SESSION[FreeResellerProgramme::DRAFT_SESSION_KEY] = ['store_name' => 'Sunrise <Hosting>'];

        $html = $this->view->render('partials.free-reseller-resume-note', []);

        $this->assertStringContainsString('Free Reseller application', $html);
        $this->assertStringContainsString('Sunrise &lt;Hosting&gt;', $html);

        unset($_SESSION[FreeResellerProgramme::DRAFT_SESSION_KEY]);
        $this->assertSame('', trim($this->view->render('partials.free-reseller-resume-note', [])));
    }

    // --- fixtures ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function facts(): array
    {
        return [
            'holdingDays' => 14,
            'payoutMinimum' => '5,000.00',
            'payoutCurrency' => 'NGN',
            'billingPeriod' => 'week',
            'serviceDiscount' => 15.0,
            'domainDiscount' => 5.0,
            'maxMarkup' => 1000.0,
            'storeDomain' => 'stores.example.com',
            'platformHost' => 'platform.example.com',
            'maxTier' => 2,
        ];
    }

    /** @return array<string, mixed> */
    private function landingData(): array
    {
        return [
            'enabled' => true,
            'adminPreview' => false,
            'headline' => FreeResellerProgramme::DEFAULT_HEADLINE,
            'tagline' => FreeResellerProgramme::DEFAULT_TAGLINE,
            'demoLinks' => [],
            'facts' => $this->facts(),
            'categories' => [
                ['id' => 1, 'name' => 'VPS Hosting', 'description' => '', 'product_count' => 3, 'starting_price' => 12.0, 'starting_cycle' => 'monthly', 'formatted_price' => '$12.00', 'icon' => 'cloud'],
                ['id' => 2, 'name' => 'Dedicated Servers', 'description' => '', 'product_count' => 2, 'starting_price' => 80.0, 'starting_cycle' => 'monthly', 'formatted_price' => '$80.00', 'icon' => 'server'],
            ],
            'tldCount' => 40,
            'domainPrices' => [['tld' => '.com', 'price' => 11.0, 'formatted_price' => '$11.00']],
            'example' => FreeResellerProgramme::earningsExample(12.0, 15.0, 30.0),
            'currencySymbol' => '$',
            'currencyRate' => 1.0,
            'client' => null,
            'ownsStore' => false,
            'canApply' => true,
        ];
    }

    /**
     * @param array<string, mixed>|null $client
     * @return array<string, mixed>
     */
    private function formData(?array $client): array
    {
        return [
            'client' => $client,
            'values' => ['store_name' => '', 'slug' => '', 'domain_mode' => 'register', 'new_domain' => '', 'existing_domain' => '', 'agree' => false],
            'errors' => [],
            'resume' => false,
            'hasDraft' => false,
            'slugSuggestion' => 'ada-obi',
            'storeDomain' => 'stores.example.com',
            'platformHost' => 'platform.example.com',
            'domainPrices' => [['tld' => 'com', 'price' => 11.0, 'formatted_price' => '$11.00']],
            'facts' => $this->facts(),
        ];
    }

    /** @return array<string, mixed> */
    private function welcomeData(string $mode, bool $inCart): array
    {
        return [
            'client' => ['id' => 42],
            'store' => ['id' => 9, 'slug' => 'sunrise', 'brand_name' => 'Sunrise Hosting', 'custom_domain' => 'mybrand.com'],
            'domain' => 'mybrand.com',
            'mode' => $mode,
            'domainError' => null,
            'domainInCart' => $inCart,
            'recordName' => '_codevault-verify.mybrand.com',
            'recordValue' => 'codevault-verify=abc123',
            'platformHost' => 'platform.example.com',
            'platformUrl' => null,
            'domainStatus' => 'pending',
            'verified' => false,
            'facts' => $this->facts(),
        ];
    }
}
