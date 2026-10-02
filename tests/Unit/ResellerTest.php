<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Api\ApiAuthException;
use CodeVault\Api\ApiAuthenticator;
use CodeVault\Api\DatabaseApiCredentialRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Request;
use CodeVault\Reseller\ApiDocumentation;
use CodeVault\Reseller\ClientResellerController;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerCredentialService;
use CodeVault\Reseller\ResellerDomainProvisioner;
use CodeVault\Reseller\ResellerDomainSync;
use CodeVault\Reseller\ResellerPricing;
use CodeVault\Reseller\ResellerRetailPriceRepository;
use CodeVault\Reseller\ResellerRetailPricing;
use CodeVault\Reseller\ResellerSettings;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The reseller programme: client-owned API keys, the domain gate that unlocks
 * them, and the discounts they get.
 *
 * The single most important property in this file is that a reseller key does
 * nothing until the client submits the domain they resell from — so a key that
 * is never completed is never able to authenticate, and an abused key is
 * traceable to a storefront. That is tested through the real
 * ApiAuthenticator (not just by reading the `active` column), because the
 * column is only meaningful if the auth path actually honours it.
 *
 * The second property is scoping: a client-owned key must never hold a scope
 * that reads install-wide data, since every other scope in the catalog returns
 * all clients / all invoices.
 */
final class ResellerTest extends DatabaseTestCase
{
    private SettingsRepository $settings;
    private ResellerSettings $resellerSettings;
    private ResellerPricing $pricing;
    private DatabaseApiCredentialRepository $credentials;
    private ResellerCredentialService $service;
    private ClientRepository $clients;
    private SessionManager $session;
    private ClientAuthGuard $guard;
    private ClientResellerController $controller;
    private ResellerStoreService $storeService;
    private ResellerStoreLocator $locator;
    private int $clientId;
    private int $otherClientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->settings = new SettingsRepository($this->db);
        $this->resellerSettings = new ResellerSettings($this->settings);
        $this->pricing = new ResellerPricing($this->db, $this->resellerSettings);
        $this->credentials = new DatabaseApiCredentialRepository($this->db);
        $this->service = new ResellerCredentialService($this->credentials);
        $this->clients = new ClientRepository($this->db);

        $configDir = sys_get_temp_dir() . '/codevault-reseller-test-' . uniqid();
        mkdir($configDir);

        $stores = new ResellerStoreRepository($this->db);
        $this->locator = new ResellerStoreLocator($stores, new Config($configDir));
        $this->storeService = new ResellerStoreService($stores, $this->locator, new DomainVerifier());

        $this->clientId = $this->clients->create([
            'email' => 'reseller@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Reseller',
            'last_name' => 'One',
        ]);

        $this->otherClientId = $this->clients->create([
            'email' => 'other-reseller@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Reseller',
            'last_name' => 'Two',
        ]);

        $_SESSION = [];
        $this->session = new SessionManager(new Config($configDir));
        $this->guard = new ClientAuthGuard($this->session, $this->clients);
        // Seed the guard's session key rather than calling login(), which
        // session_regenerate_id()s and warns in CLI (no active session).
        $_SESSION['client_id'] = $this->clientId;

        // The client page renders forms, and csrf_field() resolves the token
        // from the application container — without this the rendered page
        // throws, which is a wiring failure worth catching here rather than in
        // a browser.
        $container = new Container();
        $container->instance(SessionManager::class, $this->session);
        // The shared layout resolves settings through the container; pointing
        // those at the test database keeps a page render from reaching for the
        // application's configured DB (which is a different user/database).
        $container->instance(Database::class, $this->db);
        $container->instance(SettingsRepository::class, $this->settings);
        App::setContainer($container);

        $this->controller = new ClientResellerController(
            $this->guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->session,
            $this->service,
            $this->storeService,
            $this->locator,
            new ResellerRetailPricing($this->pricing, new ResellerRetailPriceRepository($this->db)),
            $this->resellerSettings,
            $this->pricing,
            new CurrencyService(new CurrencyRepository($this->db)),
            new ActivityLogger($this->db),
            new \CodeVault\Reseller\ResellerCostService(
                new \CodeVault\Reseller\ResellerCostRepository($this->db),
                new \CodeVault\Reseller\ResellerStoreRepository($this->db),
                new \CodeVault\Clients\ClientRepository($this->db),
                new CurrencyService(new CurrencyRepository($this->db))
            ),
            // Appended last on the controller, so it goes last here. Provisioning
            // is OFF by default, so the fake HTTP client is never reached.
            new ResellerDomainSync(
                new ResellerStoreRepository($this->db),
                new ResellerDomainProvisioner(
                    new \CodeVault\CpanelTools\CpanelUapiClient(new FakeHttpClient()),
                    new \CodeVault\Provisioning\ServerRepository($this->db),
                    $this->settings
                )
            )
        );
    }

    // --- the gate: a key is inert until a domain is submitted ---------------

    public function test_a_new_key_is_created_disabled_with_no_domain(): void
    {
        $result = $this->service->requestKey($this->clientId, 'My storefront');

        $this->assertTrue($result['created']);
        $this->assertNotEmpty($result['key']);
        $this->assertNotEmpty($result['secret']);

        $row = $this->credentials->forClient($this->clientId);

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['active'], 'a requested key must start disabled');
        $this->assertNull($row['reseller_domain']);
        $this->assertNull($row['activated_at']);
    }

    public function test_a_disabled_key_cannot_authenticate_and_works_once_the_domain_is_submitted(): void
    {
        $issued = $this->service->requestKey($this->clientId, 'My storefront');
        $auth = new ApiAuthenticator($this->credentials);
        $request = $this->apiRequest($issued['key'], $issued['secret']);

        // Before the domain: the real authentication path must refuse it.
        try {
            $auth->authenticate($request);
            $this->fail('A disabled reseller key must not authenticate.');
        } catch (ApiAuthException $e) {
            $this->assertStringContainsString('Invalid API credentials', $e->getMessage());
        }

        $this->assertTrue($this->service->activate($this->clientId, 'reseller.example.com')['success']);

        $credential = $auth->authenticate($request);

        $this->assertTrue($credential->hasScope('reseller.read'));

        // ...and the same key must still be refused everywhere else: a
        // client-owned credential never gets an install-wide scope.
        $this->expectException(ApiAuthException::class);
        $auth->authorize($credential, 'clients.read');
    }

    public function test_requesting_a_key_twice_does_not_mint_a_second_one(): void
    {
        $first = $this->service->requestKey($this->clientId, 'My storefront');
        $second = $this->service->requestKey($this->clientId, 'My storefront');

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertNull($second['secret'], 'the secret is not stored, so a second call cannot return it');
        $this->assertSame(1, count($this->credentials->allResellers()));
    }

    public function test_activation_records_a_normalised_domain(): void
    {
        $this->service->requestKey($this->clientId, 'My storefront');

        // What someone actually pastes: a scheme, mixed case, a port, a path.
        $result = $this->service->activate($this->clientId, 'HTTPS://Reseller.Example.COM:8443/pricing?x=1');

        $this->assertTrue($result['success']);
        $this->assertSame('reseller.example.com', $result['domain']);

        $row = $this->credentials->forClient($this->clientId);

        $this->assertSame(1, (int) $row['active']);
        $this->assertSame('reseller.example.com', $row['reseller_domain']);
        $this->assertNotNull($row['activated_at']);
    }

    #[DataProvider('rejectedDomains')]
    public function test_a_value_that_is_not_a_hostname_cannot_unlock_a_key(string $domain): void
    {
        $this->service->requestKey($this->clientId, 'My storefront');

        $result = $this->service->activate($this->clientId, $domain);

        $this->assertFalse($result['success'], "'{$domain}' must not pass the domain gate");
        $this->assertNull($result['domain']);

        $row = $this->credentials->forClient($this->clientId);

        $this->assertSame(0, (int) $row['active'], 'a rejected domain must leave the key disabled');
        $this->assertNull($row['reseller_domain']);
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedDomains(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'no tld' => ['localhost'],
            'bare ip' => ['192.168.1.1'],
            'single label' => ['example'],
            'space inside' => ['not a domain.com'],
            'leading hyphen' => ['-bad.example.com'],
            'underscore' => ['my_store.example.com'],
            'numeric tld' => ['example.12'],
            'trailing hyphen' => ['bad-.example.com'],
        ];
    }

    public function test_activation_without_a_key_says_so_instead_of_creating_one(): void
    {
        $result = $this->service->activate($this->clientId, 'reseller.example.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Request an API key first', (string) $result['error']);
        $this->assertSame([], $this->credentials->allResellers());
    }

    public function test_rotating_issues_a_new_secret_and_returns_the_key_to_disabled(): void
    {
        $first = $this->service->requestKey($this->clientId, 'My storefront');
        $this->service->activate($this->clientId, 'reseller.example.com');

        $rotated = $this->service->rotateKey($this->clientId, 'My storefront');

        $this->assertNotSame($first['secret'], $rotated['secret']);

        $auth = new ApiAuthenticator($this->credentials);

        // The old pair is dead...
        try {
            $auth->authenticate($this->apiRequest($first['key'], $first['secret']));
            $this->fail('The rotated-away key must stop working.');
        } catch (ApiAuthException $e) {
            $this->assertStringContainsString('Invalid API credentials', $e->getMessage());
        }

        // ...and the new one is disabled until the domain is submitted again,
        // so rotation can never leave a live key the reseller did not re-arm.
        try {
            $auth->authenticate($this->apiRequest($rotated['key'], $rotated['secret']));
            $this->fail('A newly rotated key must start disabled.');
        } catch (ApiAuthException $e) {
            $this->assertStringContainsString('Invalid API credentials', $e->getMessage());
        }

        $row = $this->credentials->forClient($this->clientId);

        $this->assertNull($row['reseller_domain']);
    }

    // --- ownership ---------------------------------------------------------

    public function test_one_client_cannot_act_on_another_clients_key(): void
    {
        $this->service->requestKey($this->clientId, 'Mine');
        $this->service->requestKey($this->otherClientId, 'Theirs');

        $mine = $this->credentials->forClient($this->clientId);
        $theirs = $this->credentials->forClient($this->otherClientId);

        // The repository takes both the credential id and the client id, so
        // pairing one client's credential id with another client's id fails.
        $this->assertFalse($this->credentials->activateForClient((int) $mine['id'], $this->otherClientId, 'sneaky.example.com'));
        $this->assertFalse($this->credentials->setActiveForClient((int) $theirs['id'], $this->clientId, false));

        $this->assertSame(0, (int) $this->credentials->forClient($this->clientId)['active']);
        $this->assertSame(0, (int) $this->credentials->forClient($this->otherClientId)['active']);
    }

    public function test_the_client_page_uses_the_signed_in_client_not_a_posted_id(): void
    {
        $this->service->requestKey($this->clientId, 'Mine');
        $this->service->requestKey($this->otherClientId, 'Theirs');

        // A signed-in client posts someone else's id along with their domain.
        $request = new Request([], [
            'domain' => 'mine.example.com',
            'client_id' => (string) $this->otherClientId,
        ], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'REQUEST_URI' => '/client/reseller/activate'], []);

        $this->controller->activate($request);

        // The id in the body is ignored: the session's client is what changes.
        $this->assertSame(1, (int) $this->credentials->forClient($this->clientId)['active']);
        $this->assertSame('mine.example.com', $this->credentials->forClient($this->clientId)['reseller_domain']);

        $other = $this->credentials->forClient($this->otherClientId);

        $this->assertSame(0, (int) $other['active'], "another client's key must be untouched");
        $this->assertNull($other['reseller_domain']);
    }

    // --- scoping -----------------------------------------------------------

    public function test_a_reseller_key_holds_only_the_reseller_scope(): void
    {
        $this->assertSame(['reseller.read'], ResellerCredentialService::SCOPES);

        $this->service->requestKey($this->clientId, 'My storefront');

        $row = $this->credentials->forClient($this->clientId);
        $scopes = json_decode((string) $row['scopes'], true);

        $this->assertSame(['reseller.read'], $scopes);
        $this->assertNotContains('clients.read', $scopes);
        $this->assertNotContains('invoices.read', $scopes);
        $this->assertNotContains('*', $scopes, 'a wildcard would hand a client the whole install');
    }

    // --- discounts ---------------------------------------------------------

    public function test_discounts_default_to_zero_and_are_clamped_to_a_sane_range(): void
    {
        $this->assertSame(['service' => 0.0, 'domain' => 0.0], $this->resellerSettings->all());

        $this->resellerSettings->save(150.0, -5.0);

        $this->assertSame(['service' => 100.0, 'domain' => 0.0], $this->resellerSettings->all());

        // A percentage below 100 with more precision than cents is rounded, not
        // truncated — the stored value is what every quote is computed from.
        $this->resellerSettings->save(12.345, 7.5);

        $this->assertSame(12.35, $this->resellerSettings->serviceDiscount());
        $this->assertSame(7.5, $this->resellerSettings->domainDiscount());
    }

    public function test_reseller_prices_subtract_the_configured_discount(): void
    {
        $this->resellerSettings->save(20.0, 10.0);

        $this->assertSame(80.0, $this->pricing->resellerPrice(100.0, 'service'));
        $this->assertSame(13.5, $this->pricing->resellerPrice(15.0, 'domain'));

        $this->assertSame([
            'list' => 9.99,
            'discount_percent' => 20.0,
            'reseller' => 7.99,
        ], $this->pricing->quote(9.99, 'service'));

        // 100% would be a free product; it is allowed as a configured value but
        // must never go below zero.
        $this->resellerSettings->save(100.0, 100.0);
        $this->assertSame(0.0, $this->pricing->resellerPrice(15.0, 'domain'));
    }

    public function test_the_catalogue_quotes_every_cycle_and_tld_at_the_reseller_price(): void
    {
        $this->resellerSettings->save(25.0, 10.0);
        $this->seedCatalogue();

        $services = $this->pricing->serviceCatalogue();

        // The migration seeds products and TLDs, so find this test's own rows
        // rather than assuming the catalogue is empty — an index-based
        // assertion here passes or fails depending on the seed data, which is
        // exactly how a test starts lying.
        $product = $this->firstWhere($services, static fn (array $row): bool => $row['name'] === 'Starter Hosting');

        $this->assertNotNull($product, 'the product this test created is missing from the catalogue');

        $monthly = $this->firstWhere($product['cycles'], static fn (array $row): bool => $row['cycle'] === 'monthly');

        $this->assertNotNull($monthly, 'the monthly cycle this test priced is missing');

        $this->assertSame(20.0, $monthly['price']['list']);
        $this->assertSame(25.0, $monthly['price']['discount_percent']);
        $this->assertSame(15.0, $monthly['price']['reseller']);
        // The discount applies to the setup fee too — charging full setup on a
        // discounted plan is a price the reseller did not agree to.
        $this->assertSame(3.75, $monthly['setup_fee']['reseller']);

        $domains = $this->pricing->domainCatalogue();

        $com = $this->firstWhere($domains, static fn (array $row): bool => $row['tld'] === '.com');

        $this->assertNotNull($com, '.com is missing from the domain catalogue');
        $this->assertSame(13.5, $com['register']['reseller']);
        $this->assertSame(16.2, $com['renew']['reseller']);
        // Transfer is a third price the admin can set independently...
        $this->assertSame(13.5, $com['transfer']['reseller']);
        // ...and all three are quoted, not just the register price.
        $this->assertSame(10.0, $com['renew']['discount_percent']);
    }

    // --- the page a reseller actually uses ---------------------------------

    public function test_the_client_reseller_page_shows_the_key_state_and_discounted_prices(): void
    {
        $this->resellerSettings->save(20.0, 10.0);
        $this->seedCatalogue();

        $issued = $this->service->requestKey($this->clientId, 'My storefront');
        $this->service->activate($this->clientId, 'reseller.example.com');

        $page = (string) $this->controller->index($this->getRequest())->body();

        // The key and the domain it was issued against are both on the page —
        // the domain is the whole reason the key exists, so a reseller can see
        // which storefront it belongs to.
        $this->assertStringContainsString($issued['key'], $page);
        $this->assertStringContainsString('reseller.example.com', $page);
        $this->assertStringContainsString('reseller.read', $page);
        $this->assertStringContainsString('20.00%', $page);

        // $20.00 list at 20% off, and $15.00 at 10% off, formatted in the
        // install's currency. These are the RESELLER figures, so a page that
        // renders list price where the discount belongs fails here.
        $this->assertStringContainsString('$16.00', $page);
        $this->assertStringContainsString('$13.50', $page);
    }

    public function test_the_client_reseller_page_offers_the_key_and_domain_steps_before_a_key_exists(): void
    {
        $page = (string) $this->controller->index($this->getRequest())->body();

        // Nothing requested yet: the page must offer the first step, and must
        // not pretend a key exists.
        $this->assertStringContainsString('/client/reseller/key', $page);
        $this->assertStringContainsString('Request API key', $page);
        $this->assertStringNotContainsString('/client/reseller/activate', $page);

        // After requesting one, the domain step appears instead, and the key is
        // described as disabled until it is completed.
        $this->service->requestKey($this->clientId, 'My storefront');
        $page = (string) $this->controller->index($this->getRequest())->body();

        $this->assertStringContainsString('/client/reseller/activate', $page);
        $this->assertStringContainsString('Disabled', $page);
    }

    public function test_the_docs_page_documents_the_reseller_endpoint_and_its_scope(): void
    {
        $page = (string) $this->controller->docs($this->getRequest())->body();

        $this->assertStringContainsString('/api/reseller/pricing', $page);
        $this->assertStringContainsString('reseller.read', $page);
        $this->assertStringContainsString('Authorization: Bearer', $page);
        // The scope table must be honest about what a reseller key cannot do.
        $this->assertStringContainsString('clients.read', $page);
    }

    private function seedCatalogue(): void
    {
        $groups = new ProductGroupRepository($this->db);
        $products = new ProductRepository($this->db);
        $pricingRows = new ProductPricingRepository($this->db);

        $groupId = $groups->create('Hosting', 'Shared hosting plans');
        $productId = $products->create([
            'product_group_id' => $groupId,
            'name' => 'Starter Hosting',
            'pay_type' => 'paid',
        ]);
        $pricingRows->setPricing($productId, 'monthly', 5.0, 20.0);

        $domainPricing = new DomainPricingRepository($this->db);
        $domainPricing->save([
            'tld' => '.com',
            'registrar_slug' => 'local',
            'register_price' => 15.0,
            'transfer_price' => 15.0,
            'renew_price' => 18.0,
        ]);
    }

    private function getRequest(): Request
    {
        return new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_URI' => '/client/reseller',
        ], []);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function firstWhere(array $rows, callable $matches): ?array
    {
        foreach ($rows as $row) {
            if ($matches($row)) {
                return $row;
            }
        }

        return null;
    }

    // --- documentation -----------------------------------------------------

    public function test_every_documented_endpoint_is_a_registered_route(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');

        preg_match_all('~\$router->(get|post)\(\s*\'([^\']+)\'~', $routes, $matches, PREG_SET_ORDER);

        $registered = [];
        foreach ($matches as $match) {
            // {id} and {clientId} are the same slot pattern with a different
            // name; normalise both sides so the comparison is about paths.
            $registered[] = strtoupper($match[1]) . ' ' . preg_replace('~\{[^}]+\}~', '{X}', $match[2]);
        }

        $documented = [];
        foreach (ApiDocumentation::endpoints() as $endpoint) {
            $documented[] = $endpoint['method'] . ' ' . preg_replace('~\{[^}]+\}~', '{X}', $endpoint['path']);
        }

        // The point of this test: the docs page renders its own table, so a
        // path that is documented but not routed would look authoritative and
        // 404 in practice. A docs page that can drift is worse than none.
        $missing = array_diff($documented, $registered);

        $this->assertSame([], array_values($missing), 'Documented but not routed: ' . implode(', ', $missing));
    }

    public function test_documented_scopes_come_from_the_scope_catalog(): void
    {
        $catalog = DatabaseApiCredentialRepository::scopeCatalog();

        foreach (ApiDocumentation::scopes() as $scope) {
            $this->assertContains($scope['scope'], $catalog);
            $this->assertNotSame('', $scope['description'], $scope['scope'] . ' needs a description');
        }

        foreach (ApiDocumentation::endpoints() as $endpoint) {
            if ($endpoint['scope'] === 'none (public)') {
                continue;
            }

            $this->assertContains($endpoint['scope'], $catalog, $endpoint['path'] . ' documents an unknown scope');
        }

        // Exactly one scope is available to reseller keys, and the docs must
        // say so rather than leaving a reseller guessing.
        $resellerScopes = array_values(array_filter(
            ApiDocumentation::scopes(),
            static fn (array $scope): bool => $scope['reseller']
        ));

        $this->assertCount(1, $resellerScopes);
        $this->assertSame('reseller.read', $resellerScopes[0]['scope']);
    }

    // --- helpers -----------------------------------------------------------

    private function apiRequest(string $key, string $secret): Request
    {
        return new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_URI' => '/api/reseller/pricing',
        ], [
            'Authorization' => 'Bearer ' . $key . '.' . $secret,
        ]);
    }
}
