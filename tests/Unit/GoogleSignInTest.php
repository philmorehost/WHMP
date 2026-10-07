<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use Closure;
use CodeVault\Clients\ClientAuthController;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Clients\GoogleSignIn;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Provisioning\HttpClient;
use CodeVault\Request;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Security\CsrfToken;
use CodeVault\Security\SecretBox;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\View;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * "Sign in with Google" on the main website AND on reseller stores.
 *
 * The promises pinned here:
 *
 *  1. The login page shows the button when Google is set up. (It used to be the one
 *     page that never received the client id, so the button silently vanished.)
 *  2. A store uses ONLY its own Google app — switched on by its owner, allowed by the
 *     super admin, store active. It never inherits the platform's app, and the
 *     platform never uses a store's.
 *  3. Google sends the visitor back to the host the store was MATCHED on, so a store
 *     customer never lands on the platform's domain.
 *  4. The callback must carry the state we issued, not expired, for the same site.
 *  5. Only a Google-verified address signs anyone in.
 *  6. A store's secret is stored encrypted and never shown back.
 */
final class GoogleSignInTest extends TestCase
{
    private const CLIENT_ID = '123456789012-abcdefgh1234.apps.googleusercontent.com';
    private const SECRET = 'GOCSPX-abcdefghijklmnop1234';
    private const STORE_CLIENT_ID = '998877665544-storeapp5678.apps.googleusercontent.com';
    private const STORE_SECRET = 'GOCSPX-storesecretvalue9876';

    private string $root;
    private Config $config;
    /** @var array<string, string> */
    public array $settingsRows = [];
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $updates = [];
    private ?string $previousAppUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $_SERVER['REQUEST_URI'] = '/client/login';
        $this->previousAppUrl = isset($_ENV['APP_URL']) ? (string) $_ENV['APP_URL'] : null;
        $_ENV['APP_URL'] = 'https://client.example-host.com';

        $this->config = new Config(sys_get_temp_dir() . '/codevault-google-noenv-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $this->config);
        $container->instance(SessionManager::class, new SessionManager($this->config));
        $container->bind(CsrfToken::class);
        App::setContainer($container);
        // The client layout asks the container for settings (theme, currency…); give it
        // the same in-memory stand-in so no test needs a MySQL server.
        $container->instance(Database::class, $this->db());
        $this->settingsRows = [];
        $this->updates = [];
    }

    protected function tearDown(): void
    {
        if ($this->previousAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->previousAppUrl;
        }

        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures ---

    /** A Database stand-in: `settings` reads/writes go to an array, updates are recorded. */
    private function db(): Database
    {
        $test = $this;

        return new class ($test) extends Database {
            public function __construct(private readonly GoogleSignInTest $test)
            {
                parent::__construct('localhost', '3306', 'none', 'none', 'none');
            }

            public function connection(): \PDO
            {
                throw new \RuntimeException('No database in this test.');
            }

            public function select(string $sql, array $bindings = []): array
            {
                return [];
            }

            public function selectOne(string $sql, array $bindings = []): ?array
            {
                if (str_contains($sql, 'FROM settings')) {
                    $key = (string) $bindings[0];

                    return array_key_exists($key, $this->test->settingsRows) ? ['value' => $this->test->settingsRows[$key]] : null;
                }

                return null;
            }

            public function insert(string $sql, array $bindings = []): string
            {
                if (str_contains($sql, 'INTO settings')) {
                    $this->test->settingsRows[(string) $bindings[0]] = (string) $bindings[1];
                }

                return '1';
            }

            public function update(string $sql, array $bindings = []): int
            {
                $this->test->updates[] = [$sql, $bindings];

                return 1;
            }
        };
    }

    private function settings(): SettingsRepository
    {
        return new SettingsRepository($this->db());
    }

    private function box(): SecretBox
    {
        return new SecretBox(null, 'test-app-key-0123456789abcdef');
    }

    /** @param array<string, mixed>|null $store */
    private function tenant(?array $store, ?string $host = null): CurrentReseller
    {
        $tenant = new CurrentReseller();
        $tenant->set($store, $host);

        return $tenant;
    }

    /** @return array<string, mixed> */
    private function store(array $overrides = []): array
    {
        return $overrides + [
            'id' => 42,
            'client_id' => 7,
            'slug' => 'acme',
            'status' => 'active',
            'google_enabled' => 1,
            'google_client_id' => self::STORE_CLIENT_ID,
            'google_client_secret' => $this->box()->encrypt(self::STORE_SECRET),
        ];
    }

    private function google(?CurrentReseller $tenant = null, ?HttpClient $http = null): GoogleSignIn
    {
        return new GoogleSignIn($this->settings(), $this->config, $tenant, $this->box(), $http);
    }

    private function platformConfigured(): void
    {
        $this->settingsRows[GoogleSignIn::KEY_CLIENT_ID] = self::CLIENT_ID;
        $this->settingsRows[GoogleSignIn::KEY_CLIENT_SECRET] = self::SECRET;
    }

    private function request(array $query = [], string $host = 'client.example-host.com'): Request
    {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['HTTPS'] = 'on';

        return new Request($query, [], ['HTTP_HOST' => $host, 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/client/login'], []);
    }

    /**
     * The real ClientAuthController, built without a database: only what the login
     * page and the Google endpoints touch is wired in.
     */
    private function controller(GoogleSignIn $google, SessionManager $session): ClientAuthController
    {
        $controller = (new ReflectionClass(ClientAuthController::class))->newInstanceWithoutConstructor();
        $clients = (new ReflectionClass(ClientRepository::class))->newInstanceWithoutConstructor();
        $guard = new ClientAuthGuard($session, $clients);
        $view = new View($this->root . '/resources/views');
        $settings = $this->settings();
        $config = $this->config;

        Closure::bind(function () use ($guard, $view, $session, $settings, $config, $google, $clients): void {
            $this->guard = $guard;
            $this->view = $view;
            $this->session = $session;
            $this->settings = $settings;
            $this->config = $config;
            $this->clients = $clients;
            $this->siteAccess = null;
            $this->currentStore = null;
            $this->google = $google;
        }, $controller, ClientAuthController::class)();

        return $controller;
    }

    private function session(): SessionManager
    {
        return App::container()->make(SessionManager::class);
    }

    // ----------------------------------------------------- the login page ---

    public function test_the_login_page_shows_the_google_button_when_google_is_set_up(): void
    {
        $this->platformConfigured();
        $session = $this->session();
        $session->remove('client_id');

        $body = $this->controller($this->google(), $session)->loginForm($this->request())->body();

        $this->assertStringContainsString('href="/client/auth/google"', $body);
        $this->assertStringContainsString('Sign in with Google', $body);
    }

    public function test_the_login_page_has_no_google_button_when_google_is_not_set_up(): void
    {
        // A client id alone is not enough: without the secret the callback cannot work,
        // so offering the button would only produce an error.
        $this->settingsRows[GoogleSignIn::KEY_CLIENT_ID] = self::CLIENT_ID;
        $session = $this->session();
        $session->remove('client_id');

        $body = $this->controller($this->google(), $session)->loginForm($this->request())->body();

        $this->assertStringNotContainsString('/client/auth/google', $body);
    }

    public function test_the_login_page_explains_a_failed_google_sign_in(): void
    {
        $this->platformConfigured();
        $session = $this->session();
        $session->remove('client_id');

        $body = $this->controller($this->google(), $session)
            ->loginForm($this->request(['error' => 'google_cancelled']))
            ->body();

        $this->assertStringContainsString('Google sign-in was cancelled', $body);
    }

    public function test_the_register_view_shows_the_sign_up_button(): void
    {
        $html = (new View($this->root . '/resources/views'))->render('client-auth.register', [
            'error' => null, 'refCode' => '', 'googleUser' => null, 'googleClientId' => self::CLIENT_ID,
        ]);

        $this->assertStringContainsString('href="/client/auth/google"', $html);
        $this->assertStringContainsString('Sign up with Google', $html);
    }

    // ------------------------------------------- whose Google app applies ---

    public function test_the_platform_uses_its_own_app_only_when_both_keys_are_saved(): void
    {
        $this->assertNull($this->google()->credentials());

        $this->platformConfigured();
        $credentials = $this->google()->credentials();

        $this->assertSame(self::CLIENT_ID, $credentials['clientId']);
        $this->assertSame(GoogleSignIn::OWNER_PLATFORM, $credentials['owner']);
        $this->assertSame(self::CLIENT_ID, $this->google()->buttonClientId());
    }

    public function test_a_store_never_inherits_the_platforms_google_app(): void
    {
        $this->platformConfigured();
        $tenant = $this->tenant($this->store(['google_enabled' => 0, 'google_client_id' => null, 'google_client_secret' => null]), 'acme.stores.test');

        $google = $this->google($tenant);

        $this->assertNull($google->credentials());
        $this->assertSame('', $google->buttonClientId());
    }

    public function test_a_store_uses_its_own_app_when_its_owner_switched_it_on(): void
    {
        $this->platformConfigured();
        $credentials = $this->google($this->tenant($this->store(), 'acme.stores.test'))->credentials();

        $this->assertSame(self::STORE_CLIENT_ID, $credentials['clientId']);
        $this->assertSame(self::STORE_SECRET, $credentials['clientSecret']);
        $this->assertSame(GoogleSignIn::OWNER_STORE, $credentials['owner']);
        $this->assertSame(42, $credentials['storeId']);
    }

    public function test_a_store_shows_no_button_when_switched_off_suspended_or_not_allowed(): void
    {
        $this->assertNull($this->google($this->tenant($this->store(['google_enabled' => 0]), 'acme.stores.test'))->credentials());
        $this->assertNull($this->google($this->tenant($this->store(['status' => 'suspended']), 'acme.stores.test'))->credentials());
        $this->assertNull($this->google($this->tenant($this->store(['google_client_secret' => null]), 'acme.stores.test'))->credentials());

        $this->settingsRows[GoogleSignIn::KEY_RESELLERS_ALLOWED] = '0';
        $this->assertNull($this->google($this->tenant($this->store(), 'acme.stores.test'))->credentials());
    }

    // ----------------------------------------------------- redirect addresses ---

    public function test_a_store_customer_comes_back_to_the_stores_own_address(): void
    {
        $google = $this->google($this->tenant($this->store(), 'shop.acme-hosting.ng'));

        // The raw Host header says something else; the MATCHED host wins.
        $uri = $google->redirectUri($this->request([], 'evil.example.org'));

        $this->assertSame('https://shop.acme-hosting.ng/client/auth/google/callback', $uri);
        $this->assertStringNotContainsString('example-host.com', $uri);
    }

    public function test_the_platform_comes_back_to_app_url(): void
    {
        $this->platformConfigured();

        $this->assertSame(
            'https://client.example-host.com/client/auth/google/callback',
            $this->google()->redirectUri($this->request([], 'client.example-host.com'))
        );
    }

    public function test_store_callback_urls_cover_every_address_once(): void
    {
        $this->assertSame(
            ['https://acme.stores.test/client/auth/google/callback', 'https://shop.acme.ng/client/auth/google/callback'],
            GoogleSignIn::callbackUrlsFor(['acme.stores.test', 'SHOP.ACME.NG', 'shop.acme.ng', '', null])
        );
    }

    // ------------------------------------------------------------ the flow ---

    public function test_the_redirect_keeps_a_state_and_sends_the_visitor_to_google(): void
    {
        $this->platformConfigured();
        $session = $this->session();

        $response = $this->controller($this->google(), $session)->googleRedirect($this->request());
        $location = (string) ($response->headers()['Location'] ?? '');
        $pending = $session->get(GoogleSignIn::SESSION_KEY);

        $this->assertStringStartsWith(GoogleSignIn::AUTHORIZE_URL . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(self::CLIENT_ID, $query['client_id']);
        $this->assertSame($pending['state'], $query['state']);
        $this->assertSame('https://client.example-host.com/client/auth/google/callback', $query['redirect_uri']);
        $this->assertSame($pending['redirect'], $query['redirect_uri']);
        $this->assertSame('select_account', $query['prompt']);
    }

    public function test_a_callback_with_a_wrong_or_missing_state_is_refused(): void
    {
        $this->platformConfigured();
        $session = $this->session();
        $session->set(GoogleSignIn::SESSION_KEY, ['state' => 'expected-state', 'redirect' => 'x', 'owner' => 'platform', 'storeId' => null, 'at' => time()]);

        $response = $this->controller($this->google(), $session)
            ->googleCallback($this->request(['state' => 'forged', 'code' => 'abc']));

        $this->assertSame('/client/login?error=google_expired', $response->headers()['Location'] ?? null);
        $this->assertNull($session->get(GoogleSignIn::SESSION_KEY), 'a state is single-use');
    }

    public function test_pending_sign_ins_expire_and_must_match(): void
    {
        $pending = ['state' => 'abc123', 'redirect' => 'https://x/cb', 'owner' => 'store', 'storeId' => 42, 'at' => 1000];

        $this->assertNotNull(GoogleSignIn::matchPending($pending, 'abc123', 1000 + 60));
        $this->assertNull(GoogleSignIn::matchPending($pending, 'abc124', 1000 + 60));
        $this->assertNull(GoogleSignIn::matchPending($pending, 'abc123', 1000 + GoogleSignIn::STATE_TTL_SECONDS + 1));
        $this->assertNull(GoogleSignIn::matchPending(null, 'abc123', 1000));
        $this->assertNull(GoogleSignIn::matchPending($pending, '', 1000));
    }

    public function test_the_code_is_exchanged_with_the_exact_redirect_address(): void
    {
        $http = new class () implements HttpClient {
            /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
            public array $calls = [];

            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                $this->calls[] = compact('method', 'url', 'headers', 'body');

                return str_contains($url, 'token')
                    ? ['status' => 200, 'body' => '{"access_token":"ya29.token"}']
                    : ['status' => 200, 'body' => '{"id":"1098","email":"Ada@Example.com","verified_email":true,"given_name":"Ada","family_name":"Obi"}'];
            }
        };

        $profile = $this->google(null, $http)->fetchProfile(
            ['clientId' => self::STORE_CLIENT_ID, 'clientSecret' => self::STORE_SECRET],
            'auth-code',
            'https://shop.acme.ng/client/auth/google/callback'
        );

        $this->assertSame(['email' => 'ada@example.com', 'first_name' => 'Ada', 'last_name' => 'Obi', 'google_id' => '1098'], $profile);
        $this->assertSame(GoogleSignIn::TOKEN_URL, $http->calls[0]['url']);
        parse_str((string) $http->calls[0]['body'], $form);
        $this->assertSame('https://shop.acme.ng/client/auth/google/callback', $form['redirect_uri']);
        $this->assertSame(self::STORE_CLIENT_ID, $form['client_id']);
        $this->assertSame('Bearer ya29.token', $http->calls[1]['headers']['Authorization']);
    }

    public function test_only_a_google_verified_address_is_accepted(): void
    {
        $this->assertNull(GoogleSignIn::profileFrom('{"email":"a@b.com","verified_email":false}'));
        $this->assertNull(GoogleSignIn::profileFrom('{"email":"a@b.com"}'));
        $this->assertNull(GoogleSignIn::profileFrom('{"email":"not-an-email","verified_email":true}'));
        $this->assertNull(GoogleSignIn::profileFrom('not json'));
        $this->assertSame('a@b.com', GoogleSignIn::profileFrom('{"email":"a@b.com","email_verified":true,"sub":"9"}')['email']);
    }

    // ------------------------------------------------ a store's own settings ---

    public function test_store_settings_are_validated_with_helpful_messages(): void
    {
        $this->assertTrue(GoogleSignIn::validateStoreSettings(true, self::STORE_CLIENT_ID, self::STORE_SECRET, false)['success']);
        // A blank secret keeps the saved one.
        $kept = GoogleSignIn::validateStoreSettings(true, self::STORE_CLIENT_ID, '', true);
        $this->assertTrue($kept['success']);
        $this->assertNull($kept['secret']);

        $this->assertStringContainsString('apps.googleusercontent.com', (string) GoogleSignIn::validateStoreSettings(true, 'my-app', self::STORE_SECRET, false)['error']);
        $this->assertStringContainsString('GOCSPX-', (string) GoogleSignIn::validateStoreSettings(true, self::STORE_CLIENT_ID, 'has spaces in it', false)['error']);
        $this->assertFalse(GoogleSignIn::validateStoreSettings(true, '', '', false)['success']);
        $this->assertFalse(GoogleSignIn::validateStoreSettings(true, self::STORE_CLIENT_ID, '', false)['success']);
        // Switching off never needs keys.
        $this->assertTrue(GoogleSignIn::validateStoreSettings(false, '', '', false)['success']);
    }

    public function test_a_store_secret_is_stored_encrypted(): void
    {
        $google = $this->google();
        $sealed = $google->sealSecret(self::STORE_SECRET);

        $this->assertStringStartsWith('sb1:', $sealed);
        $this->assertStringNotContainsString(self::STORE_SECRET, $sealed);
        $this->assertSame(self::STORE_SECRET, $google->openSecret($sealed));

        (new ResellerStoreRepository($this->db()))->saveGoogle(42, true, self::STORE_CLIENT_ID, $sealed);
        [$sql, $bindings] = $this->updates[0];
        $this->assertStringContainsString('google_client_secret = ?', $sql);
        $this->assertNotContains(self::STORE_SECRET, $bindings);
        $this->assertSame(42, end($bindings));
    }

    public function test_saving_without_a_new_secret_leaves_the_saved_one_alone(): void
    {
        (new ResellerStoreRepository($this->db()))->saveGoogle(42, false, self::STORE_CLIENT_ID, null);

        $this->assertStringNotContainsString('google_client_secret', $this->updates[0][0]);
    }

    public function test_the_reseller_panel_never_shows_the_secret_back(): void
    {
        $sealed = $this->box()->encrypt(self::STORE_SECRET);
        $html = (new View($this->root . '/resources/views'))->render('reseller.store', [
            'store' => $this->store(['google_client_secret' => $sealed, 'custom_domain' => null, 'domain_verified_at' => null, 'brand_name' => 'Acme']),
            'cost' => null, 'arrears' => [], 'goLive' => [],
            'chat' => ['support_whatsapp' => '', 'tawk_property_id' => '', 'tawk_widget_id' => ''],
            'phoneHint' => '', 'chatConfigured' => false,
            'platformHost' => 'client.example-host.com', 'storeDomain' => 'stores.test',
            'slugSuggestion' => 'acme', 'platformUrl' => 'https://acme.stores.test', 'recordName' => null,
            'error' => null, 'notice' => null, 'verification' => null, 'docsUrl' => '/client/reseller/docs',
            'google' => [
                'allowed' => true, 'enabled' => true, 'clientId' => self::STORE_CLIENT_ID, 'hasSecret' => true, 'live' => true,
                'callbackUrls' => ['https://acme.stores.test/client/auth/google/callback'],
                'origins' => ['https://acme.stores.test'], 'customPending' => false,
            ],
        ]);

        $this->assertStringContainsString('id="google-signin"', $html);
        $this->assertStringContainsString('action="/client/reseller/store/google"', $html);
        $this->assertStringContainsString('https://acme.stores.test/client/auth/google/callback', $html);
        $this->assertStringContainsString(self::STORE_CLIENT_ID, $html);
        $this->assertStringNotContainsString(self::STORE_SECRET, $html);
        $this->assertStringNotContainsString($sealed, $html);
    }
}
