<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Reseller\AdminResellerController;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerChat;
use CodeVault\Reseller\ResellerPlatformAddress;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\ScriptedDatabase;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * The platform address domain: the domain reseller stores get a free subdomain
 * under, chosen by the super admin.
 *
 * The install here runs on client.philmorehost.test (a client area on a subdomain,
 * the case that produced unworkable sub-subdomains), and the platform address
 * domain, when set, is resellerhub.test.
 */
final class ResellerPlatformAddressTest extends TestCase
{
    private ?string $savedAppUrl = null;

    /** @var array<string, array<string, mixed>> stores by slug */
    private array $stores = [
        'acme' => ['id' => 7, 'client_id' => 70, 'slug' => 'acme', 'status' => 'active', 'custom_domain' => null, 'domain_verified_at' => null],
        'brand' => ['id' => 8, 'client_id' => 80, 'slug' => 'brand', 'status' => 'active', 'custom_domain' => 'shop.brand.test', 'domain_verified_at' => '2026-09-01 10:00:00'],
    ];

    protected function setUp(): void
    {
        $this->savedAppUrl = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://client.philmorehost.test';
    }

    protected function tearDown(): void
    {
        if ($this->savedAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->savedAppUrl;
        }
    }

    /* ---------------------------------------------------------- the setting */

    public function testNormaliseAcceptsWhatPeoplePaste(): void
    {
        foreach ([
            'resellerhub.com',
            'ResellerHub.COM',
            '  https://resellerhub.com/  ',
            'http://resellerhub.com/store?x=1',
            '*.resellerhub.com',
            'resellerhub.com.',
            'resellerhub.com:443',
        ] as $input) {
            $this->assertSame(['domain' => 'resellerhub.com', 'error' => null], ResellerPlatformAddress::normalise($input), $input);
        }

        $this->assertSame('shops.example.co.uk', ResellerPlatformAddress::normalise('shops.example.co.uk')['domain']);
        $this->assertSame('xn--bcher-kva.example', ResellerPlatformAddress::normalise('xn--bcher-kva.example')['domain']);
    }

    public function testEmptyInputClearsTheSetting(): void
    {
        $this->assertSame(['domain' => null, 'error' => null], ResellerPlatformAddress::normalise('   '));
    }

    public function testNormaliseRejectsWhatCannotBeADomain(): void
    {
        foreach (['localhost', '192.168.1.10', 'bad_domain.com', '-lead.com', 'trail-.com', 'two..dots.com', 'example.123', 'spa ce.com', str_repeat('a', 64) . '.com'] as $input) {
            $result = ResellerPlatformAddress::normalise($input);
            $this->assertNull($result['domain'], $input);
            $this->assertNotNull($result['error'], $input);
        }
    }

    public function testAddressForNeedsBothHalves(): void
    {
        $this->assertSame('acme.resellerhub.test', ResellerPlatformAddress::addressFor('Acme', 'resellerhub.test'));
        $this->assertNull(ResellerPlatformAddress::addressFor('acme', null));
        $this->assertNull(ResellerPlatformAddress::addressFor('', 'resellerhub.test'));
    }

    public function testTheSettingIsReadOnceAndSaved(): void
    {
        $reads = 0;
        $db = (new ScriptedDatabase())->on('/FROM settings/', function (array $b) use (&$reads): array {
            $reads++;

            return ($b[0] ?? '') === ResellerPlatformAddress::KEY ? [['value' => 'ResellerHub.test']] : [];
        });
        $address = new ResellerPlatformAddress(new SettingsRepository($db));

        $this->assertSame('resellerhub.test', $address->domain());
        $this->assertSame('resellerhub.test', $address->domain());
        $this->assertSame(1, $reads, 'one settings read per request, not one per host check');

        $address->save(null);
        $this->assertNull($address->domain());
        $this->assertSame(ResellerPlatformAddress::KEY, $db->writes[0]['bindings'][0]);
        $this->assertSame('', $db->writes[0]['bindings'][1], 'clearing stores an empty value');
    }

    public function testAnUnreadableSettingMeansNotConfigured(): void
    {
        $db = (new ScriptedDatabase())->on('/FROM settings/', static function (): array {
            throw new \RuntimeException('settings table is gone');
        });

        $this->assertNull((new ResellerPlatformAddress(new SettingsRepository($db)))->domain());
    }

    /* --------------------------------------------------------- host routing */

    public function testConfiguredDomainServesSingleLabelSubdomains(): void
    {
        $locator = $this->locator('resellerhub.test');

        $this->assertSame('resellerhub.test', $locator->storeDomain());
        $this->assertSame(7, (int) $locator->resolve('acme.resellerhub.test')['store']['id']);
        $this->assertSame(7, (int) $locator->resolve('ACME.resellerhub.test.')['store']['id']);
        $this->assertNull($locator->resolve('acme.client.philmorehost.test'), 'the old sub-subdomain is no longer a store');
        $this->assertNull($locator->resolve('resellerhub.test'), 'the bare domain is nobody\'s store');
        $this->assertNull($locator->resolve('www.resellerhub.test'));
        $this->assertNull($locator->resolve('deep.acme.resellerhub.test'));
        $this->assertNull($locator->resolve('client.philmorehost.test'), 'the platform host stays the platform');
        $this->assertSame(8, (int) $locator->resolve('shop.brand.test')['store']['id'], 'verified custom domains are unaffected');
    }

    public function testUnconfiguredDomainServesNoSubdomainsAtAll(): void
    {
        $locator = $this->locator(null);

        $this->assertNull($locator->storeDomain());
        $this->assertNull($locator->resolve('acme.client.philmorehost.test'));
        $this->assertNull($locator->resolve('acme.philmorehost.test'));
        $this->assertSame(8, (int) $locator->resolve('shop.brand.test')['store']['id'], 'a verified domain is how such a store sells');
    }

    public function testAHandBuiltLocatorKeepsTheLegacyAddress(): void
    {
        // No address service injected (old call sites, older tests): subdomains of
        // the platform host, exactly as before the setting existed.
        $locator = new ResellerStoreLocator(new ResellerStoreRepository($this->storeDb(null)), new Config(dirname(__DIR__, 2)));

        $this->assertSame('client.philmorehost.test', $locator->storeDomain());
        $this->assertSame(7, (int) $locator->resolve('acme.client.philmorehost.test')['store']['id']);
    }

    public function testPublicHostIsTheVerifiedDomainThenTheFreeAddress(): void
    {
        $set = $this->locator('resellerhub.test');
        $unset = $this->locator(null);
        $acme = $this->stores['acme'];
        $brand = $this->stores['brand'];
        $claimed = ['slug' => 'claim', 'custom_domain' => 'claim.test', 'domain_verified_at' => null];

        $this->assertSame('acme.resellerhub.test', $set->publicHostFor($acme));
        $this->assertSame('shop.brand.test', $set->publicHostFor($brand));
        $this->assertSame('shop.brand.test', $unset->publicHostFor($brand));
        $this->assertNull($unset->publicHostFor($acme), 'no verified domain and no platform address: nowhere to go');

        // hostFor() always names SOMETHING (a mail sender domain needs a value).
        $this->assertSame('acme.resellerhub.test', $set->hostFor($acme));
        $this->assertSame('claim.test', $unset->hostFor($claimed));
        $this->assertSame('acme.client.philmorehost.test', $unset->hostFor($acme));
    }

    public function testPlatformUrlIsNullWithoutADomain(): void
    {
        $this->assertSame('https://acme.resellerhub.test', $this->service('resellerhub.test')->platformUrl($this->stores['acme']));
        $this->assertNull($this->service(null)->platformUrl($this->stores['acme']));
    }

    public function testAStoreCannotClaimThePlatformAddressDomain(): void
    {
        $service = $this->service('resellerhub.test');

        foreach (['resellerhub.test', 'other.resellerhub.test', 'client.philmorehost.test', 'x.client.philmorehost.test'] as $domain) {
            $result = $service->claimDomain(7, $domain);
            $this->assertFalse($result['success'], $domain);
        }

        $this->assertStringContainsString('platform address domain', (string) $service->claimDomain(7, 'resellerhub.test')['error']);
    }

    /* ------------------------------------------------------------ DNS check */

    public function testDnsCheckVerdicts(): void
    {
        $ok = AdminResellerController::dnsCheckResult('resellerhub.test', 'cv-check-1.resellerhub.test', ['203.0.113.5'], ['203.0.113.5']);
        $this->assertTrue($ok['ok']);
        $this->assertStringContainsString('points at this server', $ok['message']);

        $missing = AdminResellerController::dnsCheckResult('resellerhub.test', 'cv-check-1.resellerhub.test', [], ['203.0.113.5']);
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('*.resellerhub.test', $missing['message']);

        $elsewhere = AdminResellerController::dnsCheckResult('resellerhub.test', 'cv-check-1.resellerhub.test', ['198.51.100.9'], ['203.0.113.5']);
        $this->assertFalse($elsewhere['ok']);
        $this->assertStringContainsString('203.0.113.5', $elsewhere['message']);

        $unknown = AdminResellerController::dnsCheckResult('resellerhub.test', 'cv-check-1.resellerhub.test', ['198.51.100.9'], []);
        $this->assertTrue($unknown['ok']);
        $this->assertStringContainsString('confirm', $unknown['message']);
    }

    /* ---------------------------------------------------------------- views */

    public function testAdminOverviewShowsTheSettingAndTheAddresses(): void
    {
        $unset = $this->renderOverview(null, null);
        $this->assertStringContainsString('id="rs-platform-address"', $unset);
        $this->assertStringContainsString('action="/admin/resellers/platform-domain"', $unset);
        $this->assertStringContainsString('Not set.', $unset);
        $this->assertStringContainsString('not served (no platform address)', $unset);
        $this->assertStringNotContainsString('acme.client.philmorehost.test', $unset);
        $this->assertStringNotContainsString('Check DNS', $unset);

        $set = $this->renderOverview('resellerhub.test', ['ok' => false, 'message' => 'No wildcard record: nope.']);
        $this->assertStringContainsString('<code>acme.resellerhub.test</code>', $set);
        $this->assertStringContainsString('value="resellerhub.test"', $set);
        $this->assertStringContainsString('*.resellerhub.test', $set);
        $this->assertStringContainsString('Check DNS', $set);
        $this->assertStringContainsString('No wildcard record: nope.', $set);
    }

    public function testResellerStorePageWithoutAPlatformAddress(): void
    {
        $html = $this->render('/client/reseller/store', 'reseller.store', [
            'store' => $this->stores['acme'] + ['brand_name' => 'Acme', 'domain_verification_token' => 'tok'],
            'platformHost' => 'client.philmorehost.test', 'storeDomain' => null, 'platformUrl' => null,
            'recordName' => null, 'error' => null, 'notice' => null, 'verification' => null,
            'docsUrl' => '/client/reseller/docs', 'cost' => null, 'arrears' => [], 'goLive' => [],
            'chat' => ResellerChat::formValues($this->stores['acme']),
            'phoneHint' => '', 'chatConfigured' => false, 'slugSuggestion' => 'acme',
        ]);

        $this->assertStringContainsString('not available', $html);
        $this->assertStringNotContainsString('acme.client.philmorehost.test', $html);
    }

    /* -------------------------------------------------------------- helpers */

    private function storeDb(?string $platformDomain): ScriptedDatabase
    {
        return (new ScriptedDatabase())
            ->on('/FROM settings/', static fn (array $b): array => ($b[0] ?? '') === ResellerPlatformAddress::KEY && $platformDomain !== null
                ? [['value' => $platformDomain]]
                : [])
            ->on('/FROM resellers WHERE slug = \?/', fn (array $b): array => isset($this->stores[$b[0]]) ? [$this->stores[$b[0]]] : [])
            ->on('/FROM resellers WHERE custom_domain = \? AND domain_verified_at IS NOT NULL/', function (array $b): array {
                foreach ($this->stores as $store) {
                    if ($store['custom_domain'] === $b[0] && $store['domain_verified_at'] !== null) {
                        return [$store];
                    }
                }

                return [];
            });
    }

    private function locator(?string $platformDomain): ResellerStoreLocator
    {
        $db = $this->storeDb($platformDomain);

        return new ResellerStoreLocator(
            new ResellerStoreRepository($db),
            new Config(dirname(__DIR__, 2)),
            new ResellerPlatformAddress(new SettingsRepository($db))
        );
    }

    private function service(?string $platformDomain): ResellerStoreService
    {
        $db = $this->storeDb($platformDomain);
        $repo = new ResellerStoreRepository($db);

        return new ResellerStoreService(
            $repo,
            new ResellerStoreLocator($repo, new Config(dirname(__DIR__, 2)), new ResellerPlatformAddress(new SettingsRepository($db))),
            new DomainVerifier(static fn (): array => [], static fn (): array => [])
        );
    }

    /** @param array<string, mixed>|null $dnsCheck */
    private function renderOverview(?string $domain, ?array $dnsCheck): string
    {
        $stores = [
            ['id' => 7, 'client_id' => 70, 'slug' => 'acme', 'brand_name' => 'Acme', 'status' => 'active', 'customer_count' => 2, 'pending_orders' => 0, 'created_at' => '2026-01-01', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@x.test', 'upline_id' => null, 'upline_slug' => null, 'upline_brand' => null, 'upline_upline_id' => null],
        ];

        return $this->render('/admin/resellers', 'reseller.admin-index', [
            'discounts' => ['service' => 20.0, 'domain' => 10.0],
            'resellers' => [], 'stores' => $stores, 'platformHost' => 'client.philmorehost.test', 'activeCount' => 0,
            'storeDomain' => $domain, 'storeDomainSetting' => $domain, 'dnsCheck' => $dnsCheck,
            'error' => null, 'notice' => null, 'docsUrl' => '/admin/resellers/docs',
            'stats' => AdminResellerController::overviewStats($stores, ['domains' => 0, 'payouts' => 0, 'migrations' => 0]),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function render(string $path, string $template, array $data): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $config = new Config(sys_get_temp_dir() . '/codevault-views-noenv-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        App::setContainer($container);

        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $no, $file, $line);
        });

        try {
            return (new View(dirname(__DIR__, 2) . '/resources/views'))->render($template, $data);
        } finally {
            restore_error_handler();
        }
    }
}
