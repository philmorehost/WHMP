<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\CpanelTools\CpanelUapiClient;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Request;
use CodeVault\Reseller\ClientResellerMailController;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\MailDomainAlignment;
use CodeVault\Reseller\ResellerMailboxProvisioner;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;

/**
 * The store's support-address page: what it renders, what it refuses, and the one
 * behaviour that protects deliverability.
 *
 * THE TEST THAT MATTERS MOST is
 * test_creating_a_mailbox_for_a_misaligned_domain_does_not_switch_the_sender.
 *
 * Adopting a store's own address is NOT a strict upgrade. A store with no address is already
 * white-labelled — its name is on the message and the platform's authenticated address
 * carries it. Switching to a domain that does not authorise us turns authenticated mail
 * into mail that gets filtered, so the page creates the mailbox and then leaves the sender
 * alone until the check passes. Without that rule the feature would quietly make a
 * reseller's support replies MORE likely to land in spam.
 *
 * The DNS lookup is faked throughout, so nothing here touches the network.
 */
final class ResellerMailPageTest extends DatabaseTestCase
{
    private SettingsRepository $settings;
    private ResellerStoreRepository $stores;
    private FakeHttpClient $http;
    private SessionManager $session;
    private ClientAuthGuard $guard;
    private View $view;
    private int $clientId;
    private int $storeId;
    private int $serverId;

    /** @var array<string, array<int, string>|null> */
    private array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->settings = new SettingsRepository($this->db);
        $this->http = new FakeHttpClient();
        $this->stores = new ResellerStoreRepository($this->db);

        $clients = new ClientRepository($this->db);
        $this->clientId = $clients->create([
            'email' => 'mail-page-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'first_name' => 'Mail',
            'last_name' => 'Page',
        ]);

        $this->storeId = $this->stores->create($this->clientId, 'mailpage-' . substr(uniqid(), -6), 'Mailpage Hosting');

        $this->serverId = (new ServerRepository($this->db))->create([
            'name' => 'Platform',
            'hostname' => 'server.example.test',
            'module_slug' => 'cpanel',
            'api_username' => 'root',
            'api_token' => str_repeat('a', 40),
            'api_port' => 2087,
            'use_ssl' => 1,
            'active' => 1,
        ]);

        $this->settings->set('reseller.domain_provisioning', 'cpanel');
        $this->settings->set('reseller.cpanel_server_id', (string) $this->serverId);
        $this->settings->set('reseller.cpanel_account_user', 'clientmore');
        $this->settings->set('smtp.from_email', 'noreply@philmorehost.test');

        $configDir = sys_get_temp_dir() . '/codevault-mailpage-' . uniqid();
        mkdir($configDir);

        $_SESSION = [];
        $this->session = new SessionManager(new Config($configDir));
        $this->guard = new ClientAuthGuard($this->session, $clients);
        $_SESSION['client_id'] = $this->clientId;

        $container = new Container();
        $container->instance(SessionManager::class, $this->session);
        $container->instance(Database::class, $this->db);
        $container->instance(SettingsRepository::class, $this->settings);
        App::setContainer($container);

        $this->view = new View(dirname(__DIR__, 2) . '/resources/views');
    }

    // -------------------------------------------------------------- helpers ---

    /**
     * The checker, backed by $this->dns.
     *
     * array_key_exists rather than `?? []`: the coalescing operator cannot tell a null
     * VALUE from a MISSING KEY, so `?? []` would make it impossible to say "the lookup
     * failed" — which is the answer the class under test exists to distinguish.
     */
    private function alignment(): MailDomainAlignment
    {
        return new MailDomainAlignment(
            fn (string $name): ?array => array_key_exists($name, $this->dns) ? $this->dns[$name] : [],
            'spf.philmorehost.test',
            'default'
        );
    }

    private function controller(): ClientResellerMailController
    {
        return new ClientResellerMailController(
            $this->guard,
            $this->view,
            $this->session,
            $this->stores,
            new ResellerMailboxProvisioner(
                new CpanelUapiClient($this->http),
                new ServerRepository($this->db),
                $this->settings
            ),
            $this->settings,
            $this->alignment()
        );
    }

    private function store(): array
    {
        $store = $this->stores->find($this->storeId);
        $this->assertNotNull($store, 'the fixture store must exist');

        return $store;
    }

    /** A store whose domain is claimed and live on the panel. */
    private function liveDomain(string $domain = 'shop.example.test'): void
    {
        $this->stores->setCustomDomain($this->storeId, $domain, DomainVerifier::newToken());
        $this->stores->markDomainProvisioned($this->storeId, $domain);
    }

    private function ok(string $data = '{}'): string
    {
        return '{"result":{"status":1,"errors":null,"data":' . $data . '}}';
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function render(): array
    {
        $diagnostics = [];

        set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        try {
            $html = $this->view->render('reseller.client-mail', [
                'store' => $this->store(),
                'address' => \CodeVault\Reseller\ResellerMailIdentity::address($this->store()),
                'domain' => 'shop.example.test',
                'mailboxReady' => ['ok' => true, 'message' => 'We can create support@shop.example.test on the hosting panel.'],
                'warning' => \CodeVault\Reseller\ResellerMailIdentity::warning($this->store()),
                'remediation' => \CodeVault\Reseller\ResellerMailIdentity::remediation($this->store()),
                'aligned' => \CodeVault\Reseller\ResellerMailIdentity::isAligned($this->store()),
                'checkedAt' => (string) ($this->store()['support_email_checked_at'] ?? ''),
                'created' => $this->session->pullFlash('reseller_mailbox_created', null),
                'notice' => null,
                'error' => null,
                'platformSender' => 'noreply@philmorehost.test',
            ]);
        } finally {
            restore_error_handler();
        }

        return [$html, $diagnostics];
    }

    // ------------------------------------------------------------ rendering ---

    public function test_the_page_renders_without_diagnostics_with_nothing_set(): void
    {
        [$html, $diagnostics] = $this->render();

        $this->assertSame([], $diagnostics, 'The support-address page must not raise PHP diagnostics.');
        $this->assertStringContainsString('Your support address', $html);
        $this->assertStringContainsString('noreply@philmorehost.test', $html, 'With no address, the page must name the one actually used.');
        $this->assertStringContainsString('Mailpage Hosting', $html);
    }

    public function test_the_page_renders_without_diagnostics_for_a_misaligned_address(): void
    {
        $this->stores->setSupportEmail($this->storeId, 'support@shop.example.test');
        $this->stores->recordMailCheck($this->storeId, 'misaligned', 'v=spf1 include:spf.philmorehost.test ~all');

        [$html, $diagnostics] = $this->render();

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('not yet authorised', $html);
        // The fix, not just the complaint — a warning that does not say what to DO is half
        // a warning.
        $this->assertStringContainsString('spf.philmorehost.test', $html);
    }

    public function test_the_page_renders_the_one_time_credentials_panel(): void
    {
        $this->session->flash('reseller_mailbox_created', [
            'address' => 'support@shop.example.test',
            'password' => 'Abcd1234!wxyz9876',
            'adopted' => false,
            'status' => 'misaligned',
            'message' => 'support@shop.example.test is ready.',
        ]);

        [$html, $diagnostics] = $this->render();

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('Abcd1234!wxyz9876', $html);
        $this->assertStringContainsString('shown once', $html);
        $this->assertStringContainsString('We have not switched your sending address', $html);
    }

    // ------------------------------------------------------------ the refusals ---

    public function test_a_malformed_address_is_refused_and_nothing_is_stored(): void
    {
        // Stored, it would make SmtpMailer throw and the customer would receive NOTHING —
        // so this is checked at the door rather than complained about later.
        $this->controller()->save(new Request([], ['support_email' => 'not-an-address'], ['REQUEST_METHOD' => 'POST'], []));

        $this->assertNull($this->store()['support_email']);
        $this->assertStringContainsString('not a valid email address', (string) $this->session->pullFlash('reseller_error'));
    }

    public function test_the_page_reports_why_a_mailbox_cannot_be_created_yet(): void
    {
        // No domain at all: report the reason rather than hiding the button, because "claim
        // a domain first" is actionable and a missing button is not.
        $controller = $this->controller();
        $response = $controller->index(new Request([], [], ['REQUEST_METHOD' => 'GET'], []));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Claim a domain for your store first', $response->body());
    }

    // ---------------------------------------------------------------- the pair ---

    /**
     * The risk this rule exists to prevent: making a reseller's support mail MORE likely to
     * be filtered, while telling them they had upgraded.
     */
    public function test_creating_a_mailbox_for_a_misaligned_domain_does_not_switch_the_sender(): void
    {
        $this->liveDomain();
        $this->http->respondWith(200, $this->ok());
        // No SPF and no DKIM for this domain.
        $this->dns = ['shop.example.test' => [], 'default._domainkey.shop.example.test' => []];

        $this->controller()->create(new Request([], [], ['REQUEST_METHOD' => 'POST'], []));

        $store = $this->store();
        $this->assertNull($store['support_email'], 'the sender must NOT change while the domain is unauthenticated');
        $this->assertSame('misaligned', (string) $store['support_email_status']);

        $created = $this->session->pullFlash('reseller_mailbox_created', null);
        $this->assertIsArray($created);
        $this->assertFalse($created['adopted'], 'the reseller must be told the address is not in use yet');
        $this->assertSame('support@shop.example.test', $created['address']);
    }

    public function test_creating_a_mailbox_for_an_aligned_domain_adopts_the_address(): void
    {
        // The other half of the pair. Alone, either test passes for the wrong reason: this
        // one would pass if we always adopted, and the one above if we never did.
        $this->liveDomain();
        $this->http->respondWith(200, $this->ok());
        $this->dns = ['shop.example.test' => ['v=spf1 include:spf.philmorehost.test ~all']];

        $this->controller()->create(new Request([], [], ['REQUEST_METHOD' => 'POST'], []));

        $this->assertSame('support@shop.example.test', (string) $this->store()['support_email']);
        $this->assertSame('aligned', (string) $this->store()['support_email_status']);

        $created = $this->session->pullFlash('reseller_mailbox_created', null);
        $this->assertIsArray($created);
        $this->assertTrue($created['adopted']);
    }

    // --------------------------------------------------------------- the check ---

    public function test_saving_an_address_runs_the_check_and_records_the_result(): void
    {
        $this->dns = ['shop.example.test' => ['v=spf1 include:spf.philmorehost.test ~all']];

        $this->controller()->save(new Request([], ['support_email' => 'support@shop.example.test'], ['REQUEST_METHOD' => 'POST'], []));

        $store = $this->store();
        $this->assertSame('support@shop.example.test', (string) $store['support_email']);
        $this->assertSame('aligned', (string) $store['support_email_status']);
        $this->assertNotSame('', (string) $store['support_email_checked_at'], 'a pass must carry the date it was made, or it reads as current forever');
    }

    public function test_a_lookup_that_cannot_run_is_not_recorded_as_a_failure(): void
    {
        // "We could not tell" must not become "your domain is broken" — that sends somebody
        // to edit DNS that may be perfectly correct.
        $this->dns = ['shop.example.test' => null, 'default._domainkey.shop.example.test' => null];

        $this->controller()->save(new Request([], ['support_email' => 'support@shop.example.test'], ['REQUEST_METHOD' => 'POST'], []));

        $this->assertSame('unavailable', (string) $this->store()['support_email_status']);
    }

    public function test_clearing_goes_back_to_the_platform_address(): void
    {
        $this->stores->setSupportEmail($this->storeId, 'support@shop.example.test');

        $this->controller()->clear(new Request([], [], ['REQUEST_METHOD' => 'POST'], []));

        $store = $this->store();
        $this->assertNull($store['support_email']);

        // And the store is STILL branded: the name survives, only the address changes.
        $identity = \CodeVault\Reseller\ResellerMailIdentity::forStore($store, 'PhilmoreHost');
        $this->assertSame('Mailpage Hosting', $identity['name']);
        $this->assertArrayNotHasKey('email', $identity, 'with no address set, the platform address carries the message');
    }
}
