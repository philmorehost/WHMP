<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Seo\SeoTags;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\Theme\ThemeSettings;
use CodeVault\Container;

/**
 * The reseller storefront's tenant layer: which store a request belongs to,
 * what it is allowed to serve, and whose branding it shows.
 *
 * The security property this file exists to prove is that the Host header
 * cannot conjure a storefront. Every negative test below is the same claim from
 * a different angle: an unknown host, an unverified domain, a domain someone
 * else already claimed, or the platform's own host must all resolve to nothing
 * — because the alternative is serving a shop (with our certificates, on our
 * infrastructure) at a hostname that nobody proved they own.
 *
 * The DNS lookups are injected as fixed answers, so these tests verify our
 * logic rather than the internet's.
 */
final class ResellerStoreTest extends DatabaseTestCase
{
    private const PLATFORM_URL = 'https://platform.test';

    //
    private const PLATFORM_BRAND = 'Platform Host Inc';

    private ResellerStoreRepository $stores;
    private ResellerStoreLocator $locator;
    private ClientRepository $clients;
    private ThemeSettings $theme;
    private CurrentReseller $current;
    private int $clientId;
    private int $otherClientId;
    private string $configDir;

    /** @var array<int, array<string, mixed>> */
    private array $txtAnswers = [];

    /** @var array<int, array<string, mixed>> */
    private array $cnameAnswers = [];

    private string|false $previousAppUrl;
    private string|false $previousAppName;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        // Config::loadEnv() only fills a key that is not already set in the
        // process environment, so a .env file cannot pin these for the test —
        // whatever a previous suite left behind would win. Set them explicitly
        // and put the originals back in tearDown(); otherwise this class would
        // silently decide the platform host for every test that runs after it.
        $this->previousAppUrl = getenv('APP_URL');
        $this->previousAppName = getenv('APP_NAME');
        putenv('APP_URL=' . self::PLATFORM_URL);
        $_ENV['APP_URL'] = self::PLATFORM_URL;
        putenv('APP_NAME=Platform');
        $_ENV['APP_NAME'] = 'Platform';

        // brand_name() memoises per site for the LIFE OF THE PROCESS, and this
        // suite drops and recreates its tables between tests -- so AUTO_INCREMENT
        // restarts, store ids repeat, and a 'store:1' memo written by an earlier
        // test would be served here as this store's brand. The memo used to be a
        // `static` inside the helper and nothing could clear it; now it can.
        brand_name_forget();

        $this->configDir = sys_get_temp_dir() . '/codevault-store-test-' . uniqid();
        mkdir($this->configDir);

        $config = new Config($this->configDir);
        $this->stores = new ResellerStoreRepository($this->db);
        $this->locator = new ResellerStoreLocator($this->stores, $config);
        $this->current = new CurrentReseller();
        $this->clients = new ClientRepository($this->db);

        $this->theme = new ThemeSettings(new SettingsRepository($this->db), $this->current);

        // The platform's own brand, so "the store overrides it" and "the store
        // does not leak into the platform site" are both observable. Set via
        // theme.brand_name; ThemeSettings prefers company.name, which is left
        // unset on purpose so this is the value under test.
        $this->theme->save(self::PLATFORM_BRAND, null, '#ff8f28');

        $this->clientId = $this->clients->create([
            'email' => 'storeowner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Store',
            'last_name' => 'Owner',
        ]);

        $this->otherClientId = $this->clients->create([
            'email' => 'rival@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Rival',
            'last_name' => 'Reseller',
        ]);

        $container = new Container();
        $container->instance(Database::class, $this->db);
        $container->instance(Config::class, $config);
        $container->instance(SettingsRepository::class, new SettingsRepository($this->db));
        $container->instance(CurrentReseller::class, $this->current);
        App::setContainer($container);
    }

    protected function tearDown(): void
    {
        // brand_name() memoises per site inside the process; this class changes
        // the site between assertions, so start each test from a clean slate.
        if ($this->previousAppUrl !== false) {
            putenv('APP_URL=' . $this->previousAppUrl);
            $_ENV['APP_URL'] = $this->previousAppUrl;
        } else {
            putenv('APP_URL');
            unset($_ENV['APP_URL']);
        }

        if ($this->previousAppName !== false) {
            putenv('APP_NAME=' . $this->previousAppName);
            $_ENV['APP_NAME'] = $this->previousAppName;
        } else {
            putenv('APP_NAME');
            unset($_ENV['APP_NAME']);
        }

        // Leave no brand memoised for whatever runs next: this class changes the
        // site between assertions, and the memo would otherwise outlive it.
        brand_name_forget();

        parent::tearDown();
    }

    private function service(): ResellerStoreService
    {
        // Answers are read at call time so a test can change them between the
        // claim and the verify, which is exactly the sequence a reseller does.
        return new ResellerStoreService(
            $this->stores,
            $this->locator,
            new DomainVerifier(
                fn (string $host): array => $this->txtAnswers[$host] ?? [],
                fn (string $host): array => $this->cnameAnswers[$host] ?? []
            )
        );
    }

    // --- opening a store ---------------------------------------------------

    public function test_a_store_gets_a_usable_address_derived_from_its_name(): void
    {
        $result = $this->service()->openForClient($this->clientId, 'Acme Hosting');

        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertSame('acme-hosting', $result['store']['slug']);
        $this->assertSame('Acme Hosting', $result['store']['brand_name']);
        $this->assertSame('active', $result['store']['status']);
        $this->assertNull($result['store']['custom_domain']);
    }

    public function test_a_store_name_that_cannot_be_an_address_is_refused_with_a_reason(): void
    {
        $result = $this->service()->openForClient($this->clientId, '+++');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('web address', (string) $result['error']);
        $this->assertNull($this->stores->forClient($this->clientId));
    }

    public function test_a_client_gets_one_store_and_a_taken_address_is_refused(): void
    {
        $service = $this->service();

        $this->assertTrue($service->openForClient($this->clientId, 'Acme')['success']);

        // Same client, again: refused, and no second row.
        $again = $service->openForClient($this->clientId, 'Acme');
        $this->assertFalse($again['success']);
        $this->assertStringContainsString('already has a store', (string) $again['error']);

        // Different client, same address: refused.
        $rival = $service->openForClient($this->otherClientId, 'Acme');
        $this->assertFalse($rival['success']);
        $this->assertStringContainsString('already taken', (string) $rival['error']);
        $this->assertNull($this->stores->forClient($this->otherClientId));
    }

    // --- resolution: which store is this host? ------------------------------

    public function test_a_platform_subdomain_resolves_to_its_store(): void
    {
        $this->service()->openForClient($this->clientId, 'Acme');

        $match = $this->locator->resolve('acme.platform.test');

        $this->assertNotNull($match);
        $this->assertSame('acme', $match['store']['slug']);
        $this->assertSame('acme.platform.test', $match['host']);
    }

    public function test_the_platforms_own_host_is_never_a_store(): void
    {
        $this->service()->openForClient($this->clientId, 'Platform');

        // Even though a store exists whose slug is "platform", our own host and
        // its www are the platform.
        $this->assertNull($this->locator->resolve('platform.test'));
        $this->assertNull($this->locator->resolve('www.platform.test'));
        $this->assertNull($this->locator->resolve('PLATFORM.TEST:8443'));
    }

    public function test_an_unknown_host_resolves_to_nothing(): void
    {
        $this->service()->openForClient($this->clientId, 'Acme');

        $this->assertNull($this->locator->resolve('shop.someone-elses-domain.com'));
        $this->assertNull($this->locator->resolve('not-a-store.platform.test'));
        $this->assertNull($this->locator->resolve(''));
    }

    /** A store address is a single label: nesting must not reach a store. */
    public function test_a_nested_subdomain_is_not_a_store_address(): void
    {
        $this->service()->openForClient($this->clientId, 'Acme');

        $this->assertNull($this->locator->resolve('www.acme.platform.test'));
    }

    // --- custom domains: claimed is not the same as served -------------------

    public function test_a_claimed_domain_is_not_served_until_dns_proves_control(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        $claim = $service->claimDomain((int) $store['id'], 'shop.acme-host.com');

        $this->assertTrue($claim['success'], (string) $claim['error']);

        // Claimed but unverified: the domain resolves to NOTHING, so a request
        // on it is served as the platform site rather than as a store.
        $this->assertNull($this->locator->resolve('shop.acme-host.com'));

        // A failed verification changes nothing.
        $failed = $service->verifyDomain((int) $store['id']);
        $this->assertFalse($failed['verified']);
        $this->assertNull($this->locator->resolve('shop.acme-host.com'));

        // The right TXT record at the right name verifies it...
        $row = $this->stores->find((int) $store['id']);
        $this->txtAnswers['_codevault-verify.shop.acme-host.com'] = [
            ['type' => 'TXT', 'txt' => 'codevault-store-verify=' . $row['domain_verification_token']],
        ];

        $verified = $service->verifyDomain((int) $store['id']);
        $this->assertTrue($verified['verified'], (string) ($verified['error'] ?? ''));
        $this->assertSame('txt', $verified['method']);

        // ...and only now is the domain served.
        $match = $this->locator->resolve('shop.acme-host.com');
        $this->assertNotNull($match);
        $this->assertSame((int) $store['id'], (int) $match['store']['id']);
    }

    public function test_a_txt_record_with_the_wrong_value_does_not_verify(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        $service->claimDomain((int) $store['id'], 'shop.acme-host.com');

        $this->txtAnswers['_codevault-verify.shop.acme-host.com'] = [
            ['type' => 'TXT', 'txt' => 'codevault-store-verify=someone-elses-token'],
        ];

        $result = $service->verifyDomain((int) $store['id']);

        $this->assertFalse($result['verified']);
        // The wrong value is reported back, so a mismatch is visible instead of
        // a bare "failed".
        $this->assertContains('codevault-store-verify=someone-elses-token', $result['found']);
        $this->assertNull($this->locator->resolve('shop.acme-host.com'));
    }

    public function test_a_cname_to_the_platform_also_verifies_the_domain(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        $service->claimDomain((int) $store['id'], 'shop.acme-host.com');

        $this->cnameAnswers['shop.acme-host.com'] = [
            ['type' => 'CNAME', 'target' => 'platform.test.'],
        ];

        $result = $service->verifyDomain((int) $store['id']);

        $this->assertTrue($result['verified']);
        $this->assertSame('cname', $result['method']);
        $this->assertNotNull($this->locator->resolve('shop.acme-host.com'));
    }

    public function test_moving_to_a_new_domain_requires_verifying_again(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];
        $id = (int) $store['id'];

        $service->claimDomain($id, 'first.acme-host.com');
        $row = $this->stores->find($id);
        $this->txtAnswers['_codevault-verify.first.acme-host.com'] = [
            ['type' => 'TXT', 'txt' => 'codevault-store-verify=' . $row['domain_verification_token']],
        ];
        $this->assertTrue($service->verifyDomain($id)['verified']);
        $this->assertNotNull($this->locator->resolve('first.acme-host.com'));

        // Changing the domain must not carry the old verification over: the
        // proof was about the OLD domain.
        $service->claimDomain($id, 'second.acme-host.com');

        $this->assertNull($this->locator->resolve('second.acme-host.com'));
        $this->assertNull($this->locator->resolve('first.acme-host.com'), 'the released domain must stop resolving');
    }

    public function test_saving_the_same_domain_again_is_not_a_new_request(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];
        $id = (int) $store['id'];

        $service->claimDomain($id, 'shop.acme-host.com');
        $this->stores->approveDomain($id, null, null);
        $row = $this->stores->find($id);
        $this->txtAnswers['_codevault-verify.shop.acme-host.com'] = [
            ['type' => 'TXT', 'txt' => 'codevault-store-verify=' . $row['domain_verification_token']],
        ];
        $this->assertTrue($service->verifyDomain($id)['verified']);

        // The reseller presses "Update domain" without changing anything. That is
        // not a new request: treating it as one would drop the store back to
        // 'pending', clear the DNS proof, and take the domain off the hosting
        // panel — a live store taken dark by a no-op.
        $result = $service->claimDomain($id, 'shop.acme-host.com');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['unchanged'] ?? false, 'an identical domain must be reported as unchanged.');
        $this->assertSame('approved', (string) $this->stores->find($id)['domain_status']);
        $this->assertNotNull($this->stores->find($id)['domain_verified_at'], 'the proof must survive a no-op save');
        $this->assertNotNull($this->locator->resolve('shop.acme-host.com'), 'and the store must stay live');
    }

    public function test_a_refused_domain_can_be_submitted_again_unchanged(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];
        $id = (int) $store['id'];

        $service->claimDomain($id, 'shop.acme-host.com');
        $this->assertTrue($this->stores->rejectDomain($id, null, 'Prove it is yours first'));

        // A refusal is the one state where saving the SAME name is a deliberate
        // second attempt rather than an accident — the reseller is told to fix
        // something and try again, so it has to go back into the queue.
        $result = $service->claimDomain($id, 'shop.acme-host.com');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['unchanged'] ?? true, 'a refused domain must return to the queue.');
        $this->assertSame('pending', (string) $this->stores->find($id)['domain_status']);
    }

    public function test_a_long_store_name_suggests_a_short_address(): void
    {
        $service = $this->service();

        // The address becomes a link the reseller reads out loud and prints on cards,
        // so the suggestion is a SHORT piece of the name rather than the whole thing —
        // which is what the field would otherwise fill itself with.
        $this->assertSame('adeola', $service->suggestSlug('Adeola Integrated Ventures Nigeria'));
        $this->assertSame('acme', $service->suggestSlug('  Acme   Hosting  '));
        $this->assertSame('philmorehost', $service->suggestSlug('PhilmoreHost'));

        // Reserved infrastructure names are refused by normaliseSlug(), so the
        // suggestion moves on to the next word instead of giving up — leaving the
        // field empty for a name that HAS a usable second word is a worse answer.
        $this->assertSame('panel', $service->suggestSlug('Admin Panel'));

        // Nothing usable at all: leave it EMPTY rather than pre-fill nonsense the
        // reseller might accept without reading.
        $this->assertNull($service->suggestSlug(''));
        $this->assertNull($service->suggestSlug('!!! &&&'));

        // A single very long word is trimmed to something typeable.
        $trimmed = $service->suggestSlug('VeryLongCompanyNameThatJustKeepsGoing');
        $this->assertNotNull($trimmed);
        $this->assertLessThanOrEqual(20, strlen($trimmed));
    }

    public function test_a_domain_another_store_already_claimed_is_refused(): void
    {
        $service = $this->service();

        $mine = $service->openForClient($this->clientId, 'Acme')['store'];
        $theirs = $service->openForClient($this->otherClientId, 'Rival')['store'];

        $this->assertTrue($service->claimDomain((int) $mine['id'], 'shop.example-host.com')['success']);

        $result = $service->claimDomain((int) $theirs['id'], 'shop.example-host.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already claimed', (string) $result['error']);
        $this->assertNull($this->stores->find((int) $theirs['id'])['custom_domain']);
    }

    public function test_the_platforms_own_domain_cannot_be_claimed(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        foreach (['platform.test', 'shop.platform.test'] as $domain) {
            $result = $service->claimDomain((int) $store['id'], $domain);

            $this->assertFalse($result['success'], $domain . ' must not be claimable');
            $this->assertStringContainsString('belongs to the platform', (string) $result['error']);
        }

        $this->assertNull($this->stores->find((int) $store['id'])['custom_domain']);
    }

    public function test_releasing_a_domain_removes_it(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        $service->claimDomain((int) $store['id'], 'shop.acme-host.com');
        $service->releaseDomain((int) $store['id']);

        $this->assertNull($this->stores->find((int) $store['id'])['custom_domain']);
        $this->assertNull($this->locator->resolve('shop.acme-host.com'));
    }

    // --- branding ----------------------------------------------------------

    public function test_a_stores_brand_overrides_the_platforms_and_leaks_to_no_other_site(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        $saved = $service->saveBrand((int) $store['id'], [
            'brand_name' => 'Acme Hosting',
            'logo_url' => 'https://cdn.example.com/acme.png',
            'favicon_url' => '/uploads/acme.ico',
            'primary_color' => '#123456',
        ]);

        $this->assertTrue($saved['success'], (string) $saved['error']);

        // On the platform site: the admin's theme, untouched.
        $this->assertSame(self::PLATFORM_BRAND, $this->theme->forCurrentSite()['brandName']);

        // On the store: the store's brand, per field.
        $row = $this->stores->find((int) $store['id']);
        $this->current->set($row, 'acme.platform.test');

        $theme = $this->theme->forCurrentSite();
        $this->assertSame('Acme Hosting', $theme['brandName']);
        $this->assertSame('https://cdn.example.com/acme.png', $theme['logoUrl']);
        $this->assertSame('#123456', $theme['primaryColor']);
        $this->assertNotSame($theme['primaryColor'], $theme['primaryColorDark']);

        // And back on the platform site, the store's brand is gone again — the
        // override is a property of the request, not a process-wide mutation.
        $this->current->clear();
        $this->assertSame(self::PLATFORM_BRAND, $this->theme->forCurrentSite()['brandName']);

        // The global brand_name() helper must follow the site too — it backs
        // the {{company_name}} placeholder in outgoing mail. It memoises per
        // site rather than per process, or a cron run rendering mail for two
        // resellers would sign both with the first one's name.
        $this->assertSame(self::PLATFORM_BRAND, brand_name());

        $this->current->set($row, 'acme.platform.test');
        $this->assertSame('Acme Hosting', brand_name());

        $this->current->clear();
        $this->assertSame(self::PLATFORM_BRAND, brand_name());
    }

    public function test_a_store_with_no_brand_of_its_own_keeps_the_platforms(): void
    {
        $service = $this->service();

        // A store whose brand has been blanked — the shape a partially
        // configured store has, and the one that must not leave a storefront
        // with no name at all.
        $opened = $service->openForClient($this->clientId, 'Acme')['store'];
        $this->assertTrue($service->saveBrand((int) $opened['id'], ['brand_name' => ''])['success']);

        $this->current->set($this->stores->find((int) $opened['id']), 'acme.platform.test');

        $theme = $this->theme->forCurrentSite();

        $this->assertSame(self::PLATFORM_BRAND, $theme['brandName']);
        $this->assertSame([], $this->current->brand());
    }

    public function test_branding_input_is_validated(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        $badColour = $service->saveBrand((int) $store['id'], ['primary_color' => 'red']);
        $this->assertFalse($badColour['success']);

        // A CSS-injection attempt through a colour field, and a logo that is not
        // an image URL we can put in a src attribute.
        $this->assertFalse($service->saveBrand((int) $store['id'], ['primary_color' => '#fff;}body{display:none'])['success']);
        $this->assertFalse($service->saveBrand((int) $store['id'], ['logo_url' => 'javascript:alert(1)'])['success']);
        $this->assertFalse($service->saveBrand((int) $store['id'], ['logo_url' => '/uploads/../../etc/passwd'])['success']);

        $this->assertTrue($service->saveBrand((int) $store['id'], ['logo_url' => 'https://cdn.example.com/ok.png'])['success']);
    }

    public function test_a_hostile_colour_in_the_database_cannot_reach_the_css(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        // Simulate a row edited outside the application, past the validation.
        // Kept to the column's 7 characters — the schema is itself a limit on
        // what can get in, and this test is about the second line of defence.
        $this->db->update(
            'UPDATE resellers SET primary_color = ? WHERE id = ?',
            ['#12345;', (int) $store['id']]
        );

        $this->current->set($this->stores->find((int) $store['id']), 'acme.platform.test');

        $theme = $this->theme->forCurrentSite();

        $this->assertNotSame('#12345;', $theme['primaryColor']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $theme['primaryColor']);
    }

    // --- canonical URLs ----------------------------------------------------

    public function test_canonical_urls_use_the_matched_store_host(): void
    {
        $config = new Config($this->configDir);
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        $seo = new SeoTags($config, $this->current);

        // Platform site: APP_URL, exactly as before storefronts existed.
        $this->assertSame(self::PLATFORM_URL . '/store', $seo->canonicalUrl('/store'));

        $this->current->set($this->stores->find((int) $store['id']), 'acme.platform.test');

        $this->assertSame('https://acme.platform.test/store', $seo->canonicalUrl('/store'));
    }

    // --- suspension --------------------------------------------------------

    public function test_a_suspended_store_is_matched_but_not_active(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        $service->setStatus((int) $store['id'], 'suspended');

        // It still MATCHES — the Kernel needs to know whose domain this is so it
        // can return 503 instead of quietly serving the platform's own shop at
        // the reseller's prices.
        $match = $this->locator->resolve('acme.platform.test');
        $this->assertNotNull($match);

        $this->current->set($match['store'], $match['host']);
        $this->assertTrue($this->current->isSuspended());
        $this->assertFalse($this->current->isActive());
    }

    public function test_status_must_be_a_known_value(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];

        $this->assertFalse($service->setStatus((int) $store['id'], 'deleted')['success']);
        $this->assertSame('active', $this->stores->find((int) $store['id'])['status']);
    }

    // --- address rules -----------------------------------------------------

    public function test_reserved_addresses_are_refused(): void
    {
        $service = $this->service();

        foreach (['www', 'api', 'admin', 'mail', 'checkout', 'client'] as $reserved) {
            $result = $service->openForClient($this->otherClientId, 'Ignored Name', $reserved);

            $this->assertFalse($result['success'], $reserved . ' must not be usable as a store address');
            $this->assertNull($this->stores->forClient($this->otherClientId));
        }
    }

    public function test_renaming_keeps_the_store_and_can_be_refused_without_touching_anything(): void
    {
        $service = $this->service();
        $store = $service->openForClient($this->clientId, 'Acme')['store'];
        $id = (int) $store['id'];

        $this->assertTrue($service->rename($id, 'acme-new')['success']);
        $this->assertSame('acme-new', $this->stores->find($id)['slug']);

        // A reserved word is refused and the address is left alone.
        $this->assertFalse($service->rename($id, 'admin')['success']);
        $this->assertSame('acme-new', $this->stores->find($id)['slug']);

        // So is one another store already has.
        $rival = $service->openForClient($this->otherClientId, 'Rival')['store'];
        $this->assertFalse($service->rename((int) $rival['id'], 'acme-new')['success']);
        $this->assertSame('rival', $this->stores->find((int) $rival['id'])['slug']);

        // Renaming to the same address it already has is a no-op success.
        $this->assertTrue($service->rename($id, 'acme-new')['success']);
    }
}
