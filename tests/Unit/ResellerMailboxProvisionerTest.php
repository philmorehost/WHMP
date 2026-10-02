<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\CpanelTools\CpanelUapiClient;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerMailboxProvisioner;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Creating a store's mailbox, and the cases where it must refuse to try.
 *
 * The interesting behaviour is not the happy path — it is that this class says NO in three
 * specific situations, each of which would otherwise create mail somewhere it does not
 * belong: no domain, a domain the panel does not host, and provisioning switched off.
 */
final class ResellerMailboxProvisionerTest extends DatabaseTestCase
{
    private SettingsRepository $settings;
    private FakeHttpClient $http;
    private ResellerStoreRepository $stores;
    private int $storeId;
    private int $serverId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->settings = new SettingsRepository($this->db);
        $this->http = new FakeHttpClient();

        $clients = new ClientRepository($this->db);
        $clientId = $clients->create([
            'email' => 'mailbox-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'first_name' => 'Mailbox',
            'last_name' => 'Owner',
        ]);

        $this->stores = new ResellerStoreRepository($this->db);
        $this->storeId = $this->stores->create($clientId, 'mailbox-' . substr(uniqid(), -6), 'Mailbox Store');

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
    }

    // -------------------------------------------------------------- helpers ---

    private function provisioner(): ResellerMailboxProvisioner
    {
        return new ResellerMailboxProvisioner(
            new CpanelUapiClient($this->http),
            new ServerRepository($this->db),
            $this->settings
        );
    }

    /** @param bool $withDocroot deliberately optional — see the test below. */
    private function configure(bool $withDocroot = true): void
    {
        $this->settings->set('reseller.domain_provisioning', 'cpanel');
        $this->settings->set('reseller.cpanel_server_id', (string) $this->serverId);
        $this->settings->set('reseller.cpanel_account_user', 'clientmore');

        if ($withDocroot) {
            $this->settings->set('reseller.cpanel_docroot', 'public_html/whmp/public');
        }
    }

    /** A store whose domain is claimed AND on the panel. */
    private function storeWithLiveDomain(string $domain = 'shop.example.com'): array
    {
        $this->stores->setCustomDomain($this->storeId, $domain, DomainVerifier::newToken());
        $this->stores->markDomainProvisioned($this->storeId, $domain);

        $store = $this->stores->find($this->storeId);
        $this->assertNotNull($store, 'the fixture store must exist');

        return $store;
    }

    private function ok(string $data = '{}'): string
    {
        return '{"result":{"status":1,"errors":null,"data":' . $data . '}}';
    }

    // ------------------------------------------------------------ happy path ---

    public function test_a_store_on_the_panel_gets_a_mailbox_on_its_own_domain(): void
    {
        $this->configure();
        $this->http->respondWith(200, $this->ok());

        $result = $this->provisioner()->provision($this->storeWithLiveDomain());

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('support@shop.example.com', $result['address']);

        $request = $this->http->lastRequest();
        $this->assertNotNull($request, 'the panel must have been called');

        $url = (string) $request['url'];
        $this->assertStringContainsString('add_pop', $url);
        $this->assertStringContainsString('Email', $url);

        // The arguments travel in the URL rather than a POST body, so the payload is
        // assembled from both and the URL is echoed into every message — a bare
        // "not found" here would leave nothing to diagnose.
        $payload = $url . ' ' . (string) $request['body'];

        // The local part and the domain go in SEPARATELY — UAPI's add_pop takes `email` as
        // the name and `domain` as the host, so sending the whole address as `email` is a
        // silent way to create a mailbox called "support@shop.example.com".
        $this->assertStringContainsString('support', $payload, 'URL was: ' . $url);
        $this->assertStringContainsString('shop.example.com', $payload, 'URL was: ' . $url);
        $this->assertStringNotContainsString('support%40shop.example.com', $payload, 'URL was: ' . $url);
    }

    public function test_a_generated_password_is_strong_enough_for_the_panel_to_accept(): void
    {
        // cPanel rejects single-character-class passwords, so a weak one here surfaces as a
        // failed create the reseller cannot act on. Four character classes, every time.
        for ($i = 0; $i < 25; $i++) {
            $password = ResellerMailboxProvisioner::generatePassword();

            $this->assertGreaterThanOrEqual(16, strlen($password));
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $password);
        }
    }

    public function test_the_password_is_returned_for_one_display_and_kept_nowhere(): void
    {
        $this->configure();
        $this->http->respondWith(200, $this->ok());

        $store = $this->storeWithLiveDomain();
        $result = $this->provisioner()->provision($store);

        $this->assertNotNull($result['password'], 'the reseller needs it once, to read the inbox');

        // Nothing about the mailbox is written to the store row by this class — setting
        // support_email is the CALLER's job, and the password is never persisted at all.
        // If this class ever starts storing it, this assertion is where that shows up.
        $after = $this->stores->find($this->storeId);
        $this->assertNotContains($result['password'], array_map('strval', (array) $after));

        $this->assertNull($store['support_email'], 'creating a mailbox must not silently change the sending address');
    }

    // --------------------------------------------------------------- refusals ---

    public function test_a_store_without_a_domain_is_refused(): void
    {
        $this->configure();

        $store = $this->stores->find($this->storeId);
        $result = $this->provisioner()->provision((array) $store);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no domain', $result['message']);
        $this->assertSame([], $this->http->requests, 'nothing should reach the panel without a domain');
    }

    /**
     * The guard that stops us building mail for a domain nobody serves.
     *
     * A store mid-change has `custom_domain` set to the NEW name while the panel still
     * hosts the old one. Creating the mailbox from `custom_domain` alone would put mail on a
     * domain the panel does not have — most likely refused with something obscure, but on a
     * permissive panel it would create it for a hostname that is about to disappear.
     */
    public function test_a_domain_that_is_not_on_the_panel_yet_is_refused(): void
    {
        $this->configure();

        // Claimed, but never provisioned.
        $this->stores->setCustomDomain($this->storeId, 'new.example.com', DomainVerifier::newToken());

        $result = $this->provisioner()->provision((array) $this->stores->find($this->storeId));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not on the hosting panel yet', $result['message']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_a_domain_the_panel_hosts_under_another_name_is_refused(): void
    {
        $this->configure();

        // The panel has the OLD domain; the store now claims a new one.
        $this->stores->setCustomDomain($this->storeId, 'old.example.com', DomainVerifier::newToken());
        $this->stores->markDomainProvisioned($this->storeId, 'old.example.com');
        $this->stores->setCustomDomain($this->storeId, 'new.example.com', DomainVerifier::newToken());

        $result = $this->provisioner()->provision((array) $this->stores->find($this->storeId));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not on the hosting panel yet', $result['message']);
        $this->assertSame([], $this->http->requests);
    }

    public function test_nothing_reaches_the_panel_when_provisioning_is_switched_off(): void
    {
        // The switch means "this application does not touch the hosting panel", and that has
        // to hold for mail as much as for domains.
        $this->settings->set('reseller.domain_provisioning', 'off');

        $result = $this->provisioner()->provision($this->storeWithLiveDomain());

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['skipped'], 'off is SKIPPED, not a failure — an admin does it by hand.');
        $this->assertStringContainsString('cPanel by hand', $result['message']);
        $this->assertSame([], $this->http->requests);
    }

    /**
     * The deliberate difference from ResellerDomainProvisioner.
     *
     * A mailbox is not served out of a folder, so demanding a document root would refuse to
     * create mail on an install configured for mail alone. Pinned because "require it like
     * the domain provisioner does" is the obvious-looking change that would break that.
     */
    public function test_a_document_root_is_not_required_to_create_a_mailbox(): void
    {
        $this->configure(withDocroot: false);
        $this->http->respondWith(200, $this->ok());

        $result = $this->provisioner()->provision($this->storeWithLiveDomain());

        $this->assertTrue($result['ok'], $result['message']);
    }

    // -------------------------------------------------------------- failures ---

    public function test_a_repeat_reports_success_without_revealing_a_password(): void
    {
        // A retry, or a mailbox an admin created by hand, must not read as broken — but the
        // password this call generated was NOT the one applied, so showing it would hand the
        // reseller a credential that does not work.
        $this->configure();
        $this->http->respondWith(200, '{"result":{"status":0,"errors":["The email account already exists."]}}');

        $result = $this->provisioner()->provision($this->storeWithLiveDomain());

        $this->assertTrue($result['ok'], 'already existing is the post-condition we wanted');
        $this->assertStringContainsString('already exists', $result['message']);
        $this->assertNull($result['password']);
    }

    public function test_a_refused_call_reports_the_panels_own_words(): void
    {
        // The panel's message names the file or the limit that is wrong. Replacing it with a
        // summary would throw away the only diagnosable detail, which is how the missing
        // AddonDomain module stayed invisible.
        $this->configure();
        $this->http->respondWith(200, '{"result":{"status":0,"errors":["Failed to load module \\"Email\\": Can\'t locate Cpanel/API/Email.pm"]}}');

        $result = $this->provisioner()->provision($this->storeWithLiveDomain());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Can\'t locate Cpanel/API/Email.pm', $result['message']);
    }

    public function test_an_unusable_mailbox_name_is_refused_before_calling_out(): void
    {
        $this->configure();

        $result = $this->provisioner()->provision($this->storeWithLiveDomain(), 'not a name!');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not a usable mailbox name', $result['message']);
        $this->assertSame([], $this->http->requests);
    }
}
