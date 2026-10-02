<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Clients\ClientRepository;
use CodeVault\CpanelTools\CpanelUapiClient;
use CodeVault\Database\Migrator;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\MailDomainAlignment;
use CodeVault\Reseller\ResellerMailboxJob;
use CodeVault\Reseller\ResellerMailboxProvisioner;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The daily sweep that gives live stores a mailbox.
 *
 * Two properties carry the weight:
 *
 *  1. It ADOPTS the address only when the domain authorises us. A store with no address is
 *     already white-labelled — its name is on mail carried by the platform's authenticated
 *     address — so adopting an unauthenticated one would make its support mail MORE likely
 *     to be filtered while looking like an upgrade.
 *
 *  2. It does not work the same store twice. Without a record of which domain a mailbox was
 *     made for, the job would call the panel about every eligible store every day and get
 *     "already exists" back — true, useless, and it would hide the stores that are waiting.
 */
final class ResellerMailboxJobTest extends DatabaseTestCase
{
    private SettingsRepository $settings;
    private ResellerStoreRepository $stores;
    private FakeHttpClient $http;
    private ActivityLogger $activity;
    private int $storeId;
    private int $serverId;

    /** @var array<string, array<int, string>|null> */
    private array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->settings = new SettingsRepository($this->db);
        $this->stores = new ResellerStoreRepository($this->db);
        $this->http = new FakeHttpClient();
        $this->activity = new ActivityLogger($this->db);

        $clients = new ClientRepository($this->db);
        $clientId = $clients->create([
            'email' => 'mailjob-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'first_name' => 'Mail',
            'last_name' => 'Job',
        ]);

        $this->storeId = $this->stores->create($clientId, 'mailjob-' . substr(uniqid(), -6), 'Mailjob Hosting');

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
    }

    // -------------------------------------------------------------- helpers ---

    private function job(): ResellerMailboxJob
    {
        return new ResellerMailboxJob(
            $this->stores,
            new ResellerMailboxProvisioner(
                new CpanelUapiClient($this->http),
                new ServerRepository($this->db),
                $this->settings
            ),
            // array_key_exists, not `?? []`: the coalescing operator cannot tell a null
            // VALUE from a MISSING KEY, so `?? []` could never express "the lookup failed".
            new MailDomainAlignment(
                fn (string $name): ?array => array_key_exists($name, $this->dns) ? $this->dns[$name] : [],
                'spf.philmorehost.test',
                'default'
            ),
            $this->activity
        );
    }

    /** A store that is live on the panel. */
    private function liveOnPanel(string $domain = 'shop.example.test'): void
    {
        $this->stores->setCustomDomain($this->storeId, $domain, DomainVerifier::newToken());
        $this->stores->markDomainProvisioned($this->storeId, $domain);
    }

    private function ok(string $data = '{}'): string
    {
        return '{"result":{"status":1,"errors":null,"data":' . $data . '}}';
    }

    private function store(): array
    {
        $store = $this->stores->find($this->storeId);
        $this->assertNotNull($store, 'the fixture store must exist');

        return $store;
    }

    /** @return array<int, array<string, mixed>> */
    private function activityFor(): array
    {
        return $this->activity->forSubject('reseller', $this->storeId);
    }

    // ------------------------------------------------------------- the sweep ---

    public function test_a_store_live_on_the_panel_gets_a_mailbox(): void
    {
        $this->liveOnPanel();
        $this->http->respondWith(200, $this->ok());

        $this->job()->handle();

        $store = $this->store();
        $this->assertSame('shop.example.test', (string) $store['mailbox_host'], 'the mailbox must be recorded, or the job repeats itself daily');
        $this->assertNotSame('', (string) $store['mailbox_provisioned_at']);
        $this->assertNull($store['mailbox_provision_error']);
    }

    public function test_an_aligned_domain_has_its_address_adopted(): void
    {
        $this->liveOnPanel();
        $this->http->respondWith(200, $this->ok());
        $this->dns = ['shop.example.test' => ['v=spf1 include:spf.philmorehost.test ~all']];

        $this->job()->handle();

        $store = $this->store();
        $this->assertSame('support@shop.example.test', (string) $store['support_email']);
        $this->assertSame('aligned', (string) $store['support_email_status']);
    }

    /**
     * The property that protects deliverability. The other half of the pair above: alone,
     * either test would pass for the wrong reason.
     */
    public function test_a_misaligned_domain_gets_a_mailbox_but_not_the_sending_address(): void
    {
        $this->liveOnPanel();
        $this->http->respondWith(200, $this->ok());
        $this->dns = ['shop.example.test' => [], 'default._domainkey.shop.example.test' => []];

        $this->job()->handle();

        $store = $this->store();
        // The mailbox EXISTS — the reseller needs it to read replies — but nothing sends from it.
        $this->assertSame('shop.example.test', (string) $store['mailbox_host']);
        $this->assertNull($store['support_email'], 'the sending address must not change while the domain is unauthenticated');
        $this->assertSame('misaligned', (string) $store['support_email_status']);
    }

    public function test_the_log_line_describes_what_actually_happened(): void
    {
        // `'a' . $x === 'b' ? ... : ...` groups as `('a' . $x) === 'b'` because
        // concatenation binds tighter than comparison, so an inline ternary here describes
        // the OPPOSITE of what happened while looking perfectly readable. Asserted on both
        // branches because one alone would pass with the bug present.
        $this->liveOnPanel();
        $this->http->respondWith(200, $this->ok());
        $this->dns = ['shop.example.test' => ['v=spf1 include:spf.philmorehost.test ~all']];

        $this->job()->handle();

        $entries = $this->activityFor();
        $this->assertNotSame([], $entries, 'creating a mailbox must be recorded');
        $this->assertStringContainsString('set it as the store\'s sending address', (string) $entries[0]['description']);

        // Now the other direction, on a fresh store.
        $clients = new ClientRepository($this->db);
        $otherId = $clients->create([
            'email' => 'mailjob2-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'first_name' => 'Other',
            'last_name' => 'Store',
        ]);
        $otherStore = $this->stores->create($otherId, 'mailjob2-' . substr(uniqid(), -6), 'Other Hosting');
        $this->stores->setCustomDomain($otherStore, 'other.example.test', DomainVerifier::newToken());
        $this->stores->markDomainProvisioned($otherStore, 'other.example.test');

        $this->dns = ['other.example.test' => []];
        $this->http->respondWith(200, $this->ok());

        $this->job()->handle();

        $other = $this->activity->forSubject('reseller', $otherStore);
        $this->assertStringContainsString('not adopted', (string) $other[0]['description']);
    }

    // ----------------------------------------------------------- idempotency ---

    public function test_it_does_not_work_the_same_store_twice(): void
    {
        // MISALIGNED on purpose, and the fixture choice IS the test.
        //
        // When the domain is aligned the address is adopted as well, so `support_email IS
        // NULL` excludes the store on the next run by itself — and the test would pass with
        // the mailbox_host record deleted entirely, proving nothing about the clause it is
        // named after. Leaving the address unset is what isolates that record as the only
        // thing stopping a second panel call, which is the case that actually matters: a
        // store that is NOT yet adopted is exactly the one the job would re-ask about daily.
        $this->liveOnPanel();
        $this->http->respondWith(200, $this->ok());
        $this->dns = ['shop.example.test' => [], 'default._domainkey.shop.example.test' => []];

        $this->job()->handle();
        $afterFirst = count($this->http->requests);

        $this->assertNull(
            $this->store()['support_email'],
            'the fixture must NOT adopt the address, or this test cannot isolate the mailbox record'
        );

        $this->job()->handle();

        $this->assertSame($afterFirst, count($this->http->requests), 'a second run must not call the panel again');
        $this->assertCount(1, $this->activityFor(), 'and must not log a second time');
    }

    public function test_a_store_that_has_chosen_an_address_is_left_alone(): void
    {
        // Adopting an address for a reseller who already picked one would override their
        // decision, not help them.
        $this->liveOnPanel();
        $this->stores->setSupportEmail($this->storeId, 'support@elsewhere.example');

        $this->job()->handle();

        $this->assertSame([], $this->http->requests);
        $this->assertSame('support@elsewhere.example', (string) $this->store()['support_email']);
    }

    public function test_a_store_whose_domain_is_not_on_the_panel_is_left_alone(): void
    {
        $this->stores->setCustomDomain($this->storeId, 'claimed.example.test', DomainVerifier::newToken());

        $this->job()->handle();

        $this->assertSame([], $this->http->requests, 'a mailbox can only exist on a domain the panel serves');
        $this->assertNull($this->store()['mailbox_host']);
    }

    // -------------------------------------------------------------- failures ---

    public function test_nothing_is_attempted_and_no_error_recorded_when_provisioning_is_off(): void
    {
        // OFF is not a failure. It means an admin makes mailboxes by hand, and recording an
        // error on every store would fill the portal with a problem nobody has.
        $this->liveOnPanel();
        $this->settings->set('reseller.domain_provisioning', 'off');

        $this->job()->handle();

        $this->assertSame([], $this->http->requests);
        $store = $this->store();
        $this->assertNull($store['mailbox_host']);
        $this->assertNull($store['mailbox_provision_error']);
        $this->assertSame([], $this->activityFor());
    }

    public function test_a_panel_refusal_is_recorded_and_retried_next_time(): void
    {
        // mailbox_host stays NULL on failure precisely so this retries — that is what makes
        // the job self-heal once a panel problem is fixed.
        $this->liveOnPanel();
        $this->http->respondWith(200, '{"result":{"status":0,"errors":["Failed to load module \\"Email\\""]}}');

        $this->job()->handle();

        $store = $this->store();
        $this->assertNull($store['mailbox_host'], 'a failure must not look like a mailbox');
        $this->assertStringContainsString('Failed to load module', (string) $store['mailbox_provision_error']);

        // The panel is fixed; the next run succeeds without anybody intervening.
        $this->http->respondWith(200, $this->ok());
        $this->dns = ['shop.example.test' => ['v=spf1 include:spf.philmorehost.test ~all']];

        $this->job()->handle();

        $store = $this->store();
        $this->assertSame('shop.example.test', (string) $store['mailbox_host']);
        $this->assertNull($store['mailbox_provision_error'], 'yesterday\'s reason must not linger once it is fixed');
    }
}
