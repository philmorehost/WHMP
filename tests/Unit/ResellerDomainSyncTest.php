<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\CpanelTools\CpanelUapiClient;
use CodeVault\Database\Migrator;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerDomainProvisioner;
use CodeVault\Reseller\ResellerDomainSync;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Keeping the hosting panel in step with a store's domain.
 *
 * The thing worth testing here is not "does it call the API" but the DIFFERENCE
 * between what the panel holds and what the store is allowed to use. Everything
 * that goes wrong in this feature goes wrong in that gap:
 *
 *  - a replaced domain that is never removed keeps answering for a hostname no
 *    store claims, which resolves to the PLATFORM shop at PLATFORM prices;
 *  - a removal that is reported as successful while the domain is still listed is
 *    that same failure with a reassuring log line next to it;
 *  - a deleted account takes the store row with it (ON DELETE CASCADE), so a
 *    removal that runs afterwards has nothing left to remove.
 *
 * So the assertions are mostly about ORDER and about what is NOT done: the old
 * hostname comes off before the new one goes on, an in-sync store makes no calls
 * at all, and a removal the panel did not confirm is not reported as done.
 *
 * Driven through ResellerDomainSync against a REAL database and a fake HTTP
 * client, so the store row's own state is part of every assertion — the state is
 * the product here, not the call.
 */
final class ResellerDomainSyncTest extends DatabaseTestCase
{
    /** Literal setting keys, deliberately not the controller's constants. */
    private const KEY_MODE = 'reseller.domain_provisioning';
    private const KEY_SERVER = 'reseller.cpanel_server_id';
    private const KEY_ACCOUNT = 'reseller.cpanel_account_user';
    private const KEY_DOCROOT = 'reseller.cpanel_docroot';

    private FakeHttpClient $http;
    private ResellerDomainSync $sync;
    private ResellerStoreRepository $stores;
    private ClientRepository $clients;
    private SettingsRepository $settings;
    private int $clientId;
    private int $storeId;
    private int $serverId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->settings = new SettingsRepository($this->db);
        $this->http = new FakeHttpClient();

        $this->clients = new ClientRepository($this->db);
        $this->clientId = $this->clients->create([
            'email' => 'panel-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'first_name' => 'Panel',
            'last_name' => 'Owner',
        ]);

        $this->stores = new ResellerStoreRepository($this->db);
        $this->storeId = $this->stores->create($this->clientId, 'panel-' . substr(uniqid(), -6), 'Panel');

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

        $this->sync = new ResellerDomainSync(
            $this->stores,
            new ResellerDomainProvisioner(
                new CpanelUapiClient($this->http),
                new ServerRepository($this->db),
                $this->settings
            )
        );

        $this->enableProvisioning();
    }

    // --- the happy paths ---------------------------------------------------

    public function test_an_approved_domain_is_added_to_the_panel(): void
    {
        $this->claim('shop.example.com');
        $this->approve();

        $this->http->respondWith(200, $this->ok());

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertTrue($outcome['ok'], $outcome['message']);
        $this->assertSame('shop.example.com', $outcome['added']);
        $this->assertNull($outcome['removed'], 'nothing was on the panel, so nothing should have come off it.');

        // The row, not just the return value: this is what a later sync reads.
        $this->assertSame('shop.example.com', $this->panelHost());

        $url = (string) $this->http->lastRequest()['url'];
        $this->assertStringContainsString('cpanel_jsonapi_func=addaddondomain', $url);
        $this->assertStringContainsString('newdomain=shop.example.com', $url);
        // Pointed at THIS platform's document root, so the store is served by
        // this application rather than a copy of it parked on an empty folder.
        $this->assertStringContainsString('dir=' . urlencode('public_html/whmp/public'), $url);
        $this->assertStringContainsString('cpanel_jsonapi_user=clientmore', $url);
    }

    public function test_replacing_a_domain_takes_the_old_one_off_before_the_new_one_goes_on(): void
    {
        $this->provisionApproved('old.example.com');
        $this->assertSame('old.example.com', $this->panelHost());

        // The reseller moves to a different name, and it is approved.
        $this->claim('new.example.com');
        $this->approve();

        $this->http->respondInSequence([
            $this->httpOk($this->ok()),                 // deladdondomain
            $this->httpOk($this->addonList([])),        // listaddondomains — gone
            $this->httpOk($this->ok()),                 // addaddondomain
        ]);

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertTrue($outcome['ok'], $outcome['message']);
        $this->assertSame('old.example.com', $outcome['removed']);
        $this->assertSame('new.example.com', $outcome['added']);
        $this->assertSame('new.example.com', $this->panelHost());

        // ORDER is the assertion. Removing first cannot lose anything (the old
        // name is already dead), whereas adding first would leave the panel
        // holding both whenever the removal then failed.
        $functions = $this->calledFunctions();
        $this->assertSame(
            ['addaddondomain', 'deladdondomain', 'listaddondomains', 'addaddondomain'],
            $functions
        );

        $removal = $this->requestFor('deladdondomain');
        $this->assertStringContainsString('domain=old.example.com', $removal);
    }

    public function test_a_store_already_in_step_makes_no_panel_call_at_all(): void
    {
        $this->provisionApproved('shop.example.com');

        $callsBefore = count($this->http->requests);

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertTrue($outcome['ok']);
        $this->assertFalse($outcome['changed']);
        $this->assertSame($callsBefore, count($this->http->requests), 'an approval clicked twice must not re-add the domain.');
    }

    public function test_a_pending_request_is_never_added_to_the_panel(): void
    {
        $this->claim('shop.example.com');

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertFalse($outcome['changed']);
        $this->assertSame([], $this->http->requests, 'approval is the gate; a pending request must not reach the panel.');
        $this->assertSame('', $this->panelHost());
    }

    public function test_releasing_a_domain_removes_it_from_the_panel(): void
    {
        $this->provisionApproved('shop.example.com');

        $this->stores->setCustomDomain($this->storeId, null, DomainVerifier::newToken());

        $this->http->respondInSequence([
            $this->httpOk($this->ok()),
            $this->httpOk($this->addonList([])),
        ]);

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertTrue($outcome['ok'], $outcome['message']);
        $this->assertSame('shop.example.com', $outcome['removed']);
        $this->assertSame('', $this->panelHost());
    }

    public function test_a_refused_domain_takes_the_previous_name_off_the_panel(): void
    {
        $this->provisionApproved('old.example.com');

        // The reseller asks for a different name and the admin refuses it.
        $this->claim('typo.example.com');
        $this->assertTrue($this->stores->rejectDomain($this->storeId, null, 'Not this customer\'s domain'));

        $this->http->respondInSequence([
            $this->httpOk($this->ok()),
            $this->httpOk($this->addonList([])),
        ]);

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertTrue($outcome['ok'], $outcome['message']);
        // The OLD name comes off — the refused one was never on the panel. Left
        // there, the old name would answer for a hostname no store claims.
        $this->assertSame('old.example.com', $outcome['removed']);
        $this->assertSame('', $this->panelHost());
    }

    // --- removal is verified, not trusted ---------------------------------

    public function test_a_removal_the_panel_did_not_carry_out_is_reported_as_failed(): void
    {
        $this->provisionApproved('shop.example.com');
        $this->stores->setCustomDomain($this->storeId, null, DomainVerifier::newToken());

        // The panel says the removal worked and then lists the domain anyway —
        // which is what a domain added BY HAND looks like, because cPanel keys
        // deladdondomain on the subdomain label we invented.
        $this->http->respondInSequence([
            $this->httpOk($this->ok()),
            $this->httpOk($this->addonList(['shop.example.com'])),
        ]);

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertFalse($outcome['ok'], 'a domain that is still listed must never be reported as removed.');
        $this->assertStringContainsString('still on the hosting panel', $outcome['message']);

        // And the row still records it, so the next sync tries again instead of
        // believing the job is done.
        $this->assertSame('shop.example.com', $this->panelHost());
        $this->assertStringContainsString('still on the hosting panel', (string) $this->stores->find($this->storeId)['domain_provision_error']);
    }

    public function test_a_domain_the_panel_does_not_have_is_a_successful_removal(): void
    {
        $this->provisionApproved('shop.example.com');
        $this->stores->setCustomDomain($this->storeId, null, DomainVerifier::newToken());

        // "Does not exist" is the post-condition we wanted, not an error —
        // otherwise a retry, or a domain an admin already deleted by hand, would
        // report failure forever and block the step after it.
        $this->http->respondInSequence([
            $this->httpOk($this->uapiFailure('The addon domain does not exist.')),
            $this->httpOk($this->addonList([])),
        ]);

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertTrue($outcome['ok'], $outcome['message']);
        $this->assertSame('shop.example.com', $outcome['removed']);
        $this->assertSame('', $this->panelHost());
    }

    public function test_an_unreadable_listing_is_reported_as_unconfirmed_not_as_success(): void
    {
        $this->provisionApproved('shop.example.com');
        $this->stores->setCustomDomain($this->storeId, null, DomainVerifier::newToken());

        $this->http->respondInSequence([
            $this->httpOk($this->ok()),
            // A shape we cannot read. Not "no", and certainly not "yes".
            $this->httpOk($this->ok('{"unexpected":"thing"}')),
        ]);

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertTrue($outcome['ok'], 'an unreadable answer must not block the removal.');
        $this->assertStringContainsString('did not confirm', $outcome['message']);
        $this->assertSame('', $this->panelHost());
    }

    public function test_the_addon_list_is_read_through_the_payload_envelope(): void
    {
        $this->provisionApproved('shop.example.com');
        $this->stores->setCustomDomain($this->storeId, null, DomainVerifier::newToken());

        // cPanel versions differ on whether the rows sit directly in `data` or
        // under `payload`. Reading only one of them would silently report every
        // removal as unconfirmed.
        $this->http->respondInSequence([
            $this->httpOk($this->ok()),
            $this->httpOk($this->ok('{"payload":[{"domain":"shop.example.com"}]}')),
        ]);

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertFalse($outcome['ok'], 'the domain IS in that payload, so the removal did not happen.');
        $this->assertSame('shop.example.com', $this->panelHost());
    }

    // --- the switch, and deleting an account ------------------------------

    public function test_nothing_is_touched_when_provisioning_is_off(): void
    {
        $this->settings->set(self::KEY_MODE, ResellerDomainProvisioner::MODE_OFF);

        $this->claim('shop.example.com');
        $this->approve();

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertTrue($outcome['skipped']);
        $this->assertFalse($outcome['changed']);
        $this->assertSame([], $this->http->requests);
        $this->assertSame('', $this->panelHost());
    }

    public function test_a_skipped_removal_keeps_the_record_and_names_the_hostname(): void
    {
        $this->provisionApproved('shop.example.com');

        $this->settings->set(self::KEY_MODE, ResellerDomainProvisioner::MODE_OFF);

        $this->stores->setCustomDomain($this->storeId, null, DomainVerifier::newToken());

        // The fixture's own add is already in the log, so compare counts rather
        // than asserting the log is empty.
        $callsBefore = count($this->http->requests);

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertTrue($outcome['skipped']);
        // KEPT, deliberately: nothing was attempted, so forgetting the hostname
        // here would lose the only record of what is on the server.
        $this->assertSame('shop.example.com', $this->panelHost());
        $this->assertStringContainsString('shop.example.com', $outcome['message']);
        $this->assertSame($callsBefore, count($this->http->requests), 'a skipped removal must not call the panel.');
    }

    public function test_deleting_the_account_removes_the_addon_domain(): void
    {
        $this->provisionApproved('shop.example.com');

        $this->http->respondInSequence([
            $this->httpOk($this->ok()),
            $this->httpOk($this->addonList([])),
        ]);

        $outcome = $this->sync->removeForClient($this->clientId);

        $this->assertTrue($outcome['ok'], $outcome['message']);
        $this->assertSame('shop.example.com', $outcome['removed']);
        $this->assertStringContainsString('domain=shop.example.com', $this->requestFor('deladdondomain'));
    }

    public function test_the_store_row_is_gone_once_the_account_is_deleted(): void
    {
        // Not a property of the sync — a property of the SCHEMA, and the reason
        // the removal has to run FIRST. If it ran after, there would be no row
        // left to say which hostname to take off the panel.
        $this->provisionApproved('shop.example.com');

        $this->clients->delete($this->clientId);

        $this->assertNull($this->stores->find($this->storeId), 'resellers.client_id must cascade — the removal has to happen before this.');
    }

    public function test_removing_for_an_account_with_no_store_is_a_no_op(): void
    {
        $other = $this->clients->create([
            'email' => 'plain-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'first_name' => 'Plain',
            'last_name' => 'Client',
        ]);

        $outcome = $this->sync->removeForClient($other);

        $this->assertTrue($outcome['ok']);
        $this->assertFalse($outcome['changed']);
        $this->assertSame([], $this->http->requests);
    }

    // --- the outstanding list --------------------------------------------

    public function test_outstanding_lists_a_hostname_the_store_no_longer_claims(): void
    {
        $this->provisionApproved('old.example.com');
        $this->assertSame([], $this->stores->outstandingPanelDomains(), 'an in-step store is not outstanding.');

        // Claim moved away without a successful sync — the state a refused panel
        // call or a switched-off automation leaves behind.
        $this->stores->setCustomDomain($this->storeId, 'new.example.com', DomainVerifier::newToken());

        $outstanding = $this->stores->outstandingPanelDomains();

        $this->assertCount(1, $outstanding);
        $this->assertSame('old.example.com', (string) $outstanding[0]['domain_provisioned_host']);
    }

    // --- the panel diagnostic --------------------------------------------

    public function test_the_diagnostic_reports_what_the_panel_supports(): void
    {
        $this->provisionApproved('shop.example.com');

        $this->http->respondInSequence([
            $this->httpOk($this->whmVersion('11.134.0.44')),          // WHM API 1 `version`
            $this->httpOk($this->ok($this->addonPayload(['shop.example.com']))), // listaddondomains
        ]);

        $rows = $this->provisioner()->diagnose();

        foreach ($rows as $row) {
            $this->assertNotFalse($row['ok'], $row['label'] . ' should have passed: ' . $row['detail']);
        }

        $this->assertStringContainsString('11.134.0.44', (string) $this->checkRow($rows, 'WHM reachable')['detail']);
        $this->assertStringContainsString('shop.example.com', (string) $this->checkRow($rows, 'Addon domains readable')['detail']);
    }

    public function test_the_diagnostic_quotes_the_panel_when_a_module_is_missing(): void
    {
        $this->http->respondInSequence([
            $this->httpOk($this->whmVersion('11.134.0.44')),
            // UAPI: as a real panel answered.
            $this->httpOk($this->uapiFailure('Failed to load module "AddonDomain": Can\'t locate Cpanel/API/AddonDomain.pm in @INC')),
            // The API 2 retry also refused.
            $this->httpOk($this->uapiFailure('API 2 is not available on this server.')),
        ]);

        $rows = $this->provisioner()->diagnose();
        $row = $this->checkRow($rows, 'Addon domains readable');

        $this->assertFalse($row['ok'], 'a module the panel does not have must not be reported as working.');
        // The panel's own words have to survive: they name the file, which is the
        // only thing that tells an administrator what to do next.
        $this->assertStringContainsString('AddonDomain', (string) $row['detail']);
        $this->assertStringContainsString('API 2 is not available', (string) $row['detail']);
    }

    public function test_the_diagnostic_stops_before_calling_anything_when_the_settings_are_missing(): void
    {
        $this->settings->set(self::KEY_SERVER, '');

        $rows = $this->provisioner()->diagnose();

        $this->assertCount(2, $rows, 'settings and mode are reported; nothing is called.');
        $this->assertFalse($this->checkRow($rows, 'Settings')['ok']);
        $this->assertSame([], $this->http->requests, 'an unconfigured panel must not be contacted.');
    }

    public function test_the_diagnostic_flags_a_domain_parked_on_its_own_folder(): void
    {
        // The failure that LOOKS LIKE SUCCESS: the domain was created, and the
        // panel put it in its own folder rather than the one holding the
        // application. The address then answers with the panel's error page.
        //
        // The path CONTAINS the configured document root (public_html/whmp/public)
        // in full, which is why this is the sharp version of the case: a substring
        // test would call it correct. Only a suffix match catches it.
        $this->http->respondInSequence([
            $this->httpOk($this->whmVersion('11.134.0.44')),
            $this->httpOk($this->ok($this->addonListWithRoots([
                'domain.pmhserver.name.ng' => '/home/clientmore/public_html/whmp/public/domain.pmhserver.name.ng',
            ]))),
        ]);

        $row = $this->checkRow($this->provisioner()->diagnose(), 'Domains serve this application');

        $this->assertFalse($row['ok'], 'a domain on its own subfolder does not serve this application.');
        $this->assertStringContainsString('domain.pmhserver.name.ng', (string) $row['detail']);
    }

    public function test_the_diagnostic_accepts_a_domain_pointed_at_the_platforms_folder(): void
    {
        // The fixture configures public_html/whmp/public, so a domain that ENDS
        // there is serving the same files as the platform.
        $this->http->respondInSequence([
            $this->httpOk($this->whmVersion('11.134.0.44')),
            $this->httpOk($this->ok($this->addonListWithRoots([
                'domain.pmhserver.name.ng' => '/home/clientmore/public_html/whmp/public',
            ]))),
        ]);

        $row = $this->checkRow($this->provisioner()->diagnose(), 'Domains serve this application');

        $this->assertTrue($row['ok'], (string) $row['detail']);
    }

    public function test_the_diagnostic_says_so_when_the_panel_does_not_report_a_document_root(): void
    {
        // Unknown, not "fine" and not "wrong". A verdict we cannot support is
        // worse than none: this row is what tells somebody where to look.
        $this->http->respondInSequence([
            $this->httpOk($this->whmVersion('11.134.0.44')),
            $this->httpOk($this->ok($this->addonPayload(['domain.pmhserver.name.ng']))),
        ]);

        $row = $this->checkRow($this->provisioner()->diagnose(), 'Domains serve this application');

        $this->assertNull($row['ok']);
        $this->assertStringContainsString('did not report a document root', (string) $row['detail']);
    }

    // --- helpers ----------------------------------------------------------

    private function provisioner(): ResellerDomainProvisioner
    {
        return new ResellerDomainProvisioner(
            new CpanelUapiClient($this->http),
            new ServerRepository($this->db),
            $this->settings
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function checkRow(array $rows, string $label): array
    {
        foreach ($rows as $row) {
            if ((string) $row['label'] === $label) {
                return $row;
            }
        }

        $this->fail('No ' . $label . ' row in the diagnostic. Got: '
            . implode(', ', array_map(static fn (array $r): string => (string) $r['label'], $rows)));
    }

    /** The legacy shape the WHM API 1 `version` function is confirmed to return. */
    private function whmVersion(string $version): string
    {
        return '{"cpanelresult":{"apiversion":"2","data":{"version":"' . $version . '"},"type":"text"}}';
    }

    /** @param array<int, string> $domains */
    private function addonPayload(array $domains): string
    {
        return '[' . implode(',', array_map(
            static fn (string $domain): string => '{"domain":"' . $domain . '"}',
            $domains
        )) . ']';
    }

    /**
     * A listing that reports document roots, keyed domain => absolute path — the
     * shape the document-root check reads.
     *
     * @param array<string, string> $roots
     */
    private function addonListWithRoots(array $roots): string
    {
        $rows = [];

        foreach ($roots as $domain => $root) {
            $rows[] = '{"domain":"' . $domain . '","documentroot":"' . $root . '"}';
        }

        return '[' . implode(',', $rows) . ']';
    }

    private function enableProvisioning(): void
    {
        $this->settings->set(self::KEY_MODE, ResellerDomainProvisioner::MODE_CPANEL);
        $this->settings->set(self::KEY_SERVER, (string) $this->serverId);
        $this->settings->set(self::KEY_ACCOUNT, 'clientmore');
        $this->settings->set(self::KEY_DOCROOT, 'public_html/whmp/public');
    }

    private function claim(string $domain): void
    {
        $this->stores->setCustomDomain($this->storeId, $domain, DomainVerifier::newToken());
    }

    private function approve(): void
    {
        $this->assertTrue(
            $this->stores->approveDomain($this->storeId, null, null),
            'the fixture must be in a state an approval can act on — otherwise the test proves nothing.'
        );
    }

    /** Claim, approve, and add it to the panel, so later steps start from "live". */
    private function provisionApproved(string $domain): void
    {
        $this->claim($domain);
        $this->approve();

        $this->http->respondWith(200, $this->ok());

        $outcome = $this->sync->syncStore($this->storeId);

        $this->assertTrue($outcome['ok'], 'fixture: ' . $outcome['message']);
        $this->assertSame($domain, $this->panelHost());
    }

    private function panelHost(): string
    {
        return trim((string) ($this->stores->find($this->storeId)['domain_provisioned_host'] ?? ''));
    }

    /** @return array<int, string> */
    private function calledFunctions(): array
    {
        $functions = [];

        foreach ($this->http->requests as $request) {
            preg_match('~cpanel_jsonapi_func=([a-z]+)~', (string) $request['url'], $match);
            $functions[] = (string) ($match[1] ?? '');
        }

        return $functions;
    }

    private function requestFor(string $function): string
    {
        foreach ($this->http->requests as $request) {
            if (str_contains((string) $request['url'], 'cpanel_jsonapi_func=' . $function)) {
                return (string) $request['url'];
            }
        }

        $this->fail('No ' . $function . ' call was made.');
    }

    /** @return array{status: int, body: string} */
    private function httpOk(string $body): array
    {
        return ['status' => 200, 'body' => $body];
    }

    private function ok(string $data = '{}'): string
    {
        return '{"result":{"status":1,"errors":null,"data":' . $data . '}}';
    }

    private function uapiFailure(string $message): string
    {
        return '{"result":{"status":0,"errors":["' . $message . '"]}}';
    }

    /** @param array<int, string> $domains */
    private function addonList(array $domains): string
    {
        return $this->ok('[' . implode(',', array_map(
            static fn (string $domain): string => '{"domain":"' . $domain . '"}',
            $domains
        )) . ']');
    }
}
