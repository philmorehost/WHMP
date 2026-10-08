<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Cloudflare\CloudflareAddon;
use CodeVault\Cloudflare\CloudflareApi;
use CodeVault\Cloudflare\CloudflareApiException;
use CodeVault\Cloudflare\CloudflareCronJob;
use CodeVault\Cloudflare\CloudflareFeatures;
use CodeVault\Cloudflare\CloudflareRules;
use CodeVault\Cloudflare\OriginCertificateInstaller;
use CodeVault\Cloudflare\OriginCertificateKeyGenerator;
use CodeVault\Cloudflare\CloudflareProductOption;
use CodeVault\Cloudflare\CloudflareService;
use CodeVault\Cloudflare\CloudflareSettings;
use CodeVault\Cloudflare\CloudflareTemplates;
use CodeVault\Cloudflare\CloudflareZoneRepository;
use CodeVault\Cloudflare\NameserverGateway;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Domains\DomainRepository;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Provisioning\HttpClient;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\View;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cloudflare add-on (Free plan, Provider mode). Runs the real repositories
 * against an in-memory SQLite database and the real API client against a fake
 * Cloudflare that keeps state, so each flow is checked end to end: what is
 * sent to Cloudflare, what is stored, and what the registrar is asked to do.
 */
final class CloudflareAddonTest extends TestCase
{
    private CfSqliteDatabase $db;
    private FakeCloudflare $cf;
    private FakeNameserverGateway $ns;
    private CloudflareSettings $settings;
    private CloudflareZoneRepository $zones;
    private CloudflareService $service;

    protected function setUp(): void
    {
        $this->db = new CfSqliteDatabase();
        $this->cf = new FakeCloudflare();
        $this->ns = new FakeNameserverGateway();
        $settingsRepo = new SettingsRepository($this->db);
        $this->settings = new CloudflareSettings($settingsRepo, null, $this->cf);
        $this->settings->saveToken('tok_test');
        $this->settings->save(['account_id' => 'acc123', 'account_name' => 'Hosting Co']);
        $this->zones = new CloudflareZoneRepository($this->db);
        $this->service = new CloudflareService(
            $this->settings,
            $this->zones,
            new ServiceRepository($this->db),
            $this->db,
            null,
            new DomainRepository($this->db),
            $this->ns
        );

        $this->db->exec("INSERT INTO currencies (id, code, symbol, exchange_rate, is_default) VALUES (1, 'USD', '$', 1, 1)");
        $this->db->exec("INSERT INTO clients (id, reseller_id, email, first_name, last_name) VALUES (7, NULL, 'ada@example.com', 'Ada', 'Lovelace'), (8, NULL, 'bob@example.com', 'Bob', 'B'), (9, 3, 'store@example.com', 'Sam', 'Store')");
        $this->db->exec("INSERT INTO resellers (id, client_id, brand_name, slug) VALUES (3, 50, 'Acme Hosting', 'acme')");
        $this->db->exec("INSERT INTO services (id, client_id, order_id, product_id, server_id, dedicated_ip, product_name, domain, status) VALUES (100, 7, 500, 20, NULL, '203.0.113.10', 'Starter', 'www.Example.com', 'active')");
        $this->db->exec("INSERT INTO addon_modules (slug, enabled) VALUES ('cloudflare', 1)");
        (new CloudflareProductOption($this->db, $this->settings))->syncProducts([20]);
    }

    // ------------------------------------------------------------------ pure helpers

    public function testZoneNameNormalisesAndRejectsNonDomains(): void
    {
        $this->assertSame('example.com', CloudflareService::zoneName('www.Example.com.'));
        $this->assertSame('example.co.uk', CloudflareService::zoneName('https://example.co.uk/path'));
        $this->assertNull(CloudflareService::zoneName('localhost'));
        $this->assertNull(CloudflareService::zoneName('203.0.113.9'));
        $this->assertNull(CloudflareService::zoneName(''));
        $this->assertNull(CloudflareService::zoneName('bad_domain..com'));
    }

    public function testValidateDnsBuildsCleanPayloads(): void
    {
        $a = CloudflareService::validateDns(['type' => 'a', 'name' => '@', 'content' => '198.51.100.4', 'proxied' => '1'], 'example.com');
        $this->assertSame(['type' => 'A', 'name' => 'example.com', 'content' => '198.51.100.4', 'ttl' => 1, 'proxied' => true], $a);

        $mx = CloudflareService::validateDns(['type' => 'MX', 'name' => 'example.com', 'content' => 'Mail.Example.com.', 'priority' => '5', 'ttl' => '3600', 'proxied' => '1'], 'example.com');
        $this->assertSame(['type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com', 'ttl' => 3600, 'priority' => 5], $mx, 'MX is never proxied and keeps its priority');

        $cname = CloudflareService::validateDns(['type' => 'CNAME', 'name' => 'blog', 'content' => 'example.com', 'ttl' => '5'], 'example.com');
        $this->assertSame('blog.example.com', $cname['name']);
        $this->assertSame(60, $cname['ttl'], 'TTL is clamped to Cloudflare\'s minimum');
        $this->assertFalse($cname['proxied']);

        $this->assertNull(CloudflareService::validateDns(['type' => 'A', 'name' => 'x', 'content' => '999.1.1.1'], 'example.com', $err));
        $this->assertSame('Enter a valid IPv4 address.', $err);
        $this->assertNull(CloudflareService::validateDns(['type' => 'SRV', 'name' => 'x', 'content' => 'y'], 'example.com', $err));
        $this->assertNull(CloudflareService::validateDns(['type' => 'TXT', 'name' => 'bad name!', 'content' => 'v=spf1'], 'example.com', $err));
        $this->assertSame('The record name is not valid.', $err);
    }

    public function testApiErrorsAreTranslatedAndTokenNeverLeaks(): void
    {
        $e = CloudflareApiException::fromResponse(400, ['errors' => [['code' => 1061, 'message' => 'example.com already exists']]]);
        $this->assertSame('This domain is already set up in our Cloudflare account.', $e->getMessage());
        $this->assertTrue($e->hasCode(1061));

        $e = CloudflareApiException::fromResponse(403, ['errors' => [['code' => 10000, 'message' => 'Authentication error']]]);
        $this->assertStringContainsString('API token is invalid', $e->getMessage());

        $e = CloudflareApiException::fromResponse(0, [], 'timeout');
        $this->assertStringContainsString('Could not reach Cloudflare', $e->getMessage());

        $api = new CloudflareApi($this->cf, 'secret_tok');
        $api->verifyToken();
        $this->assertSame('Bearer secret_tok', $this->cf->requests[0]['headers']['Authorization']);
        $this->assertStringNotContainsString('secret_tok', $this->cf->requests[0]['url']);

        try {
            $api->zone('../../accounts');
            $this->fail('A path-traversal id must be refused');
        } catch (CloudflareApiException $e) {
            $this->assertSame('Invalid Cloudflare identifier.', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ eligibility & order

    public function testEligibilityAndOrderChoiceFromConfigurableOption(): void
    {
        $group = $this->settings->optionGroupId();
        $yes = $this->settings->optionYesId();
        $this->assertGreaterThan(0, $group);
        $this->assertTrue($this->service->productEligible(20));
        $this->assertFalse($this->service->productEligible(21));

        $service = (new ServiceRepository($this->db))->find(100);
        $this->assertFalse($this->service->choseAtOrder($service));

        $this->db->exec("INSERT INTO order_items (order_id, product_id, configurable_options) VALUES (500, 20, '" . json_encode([(string) $group => $yes]) . "')");
        $this->assertTrue($this->service->choseAtOrder($service));

        // Every price is 0 — the option never changes an invoice.
        $prices = $this->db->select('SELECT DISTINCT price FROM configurable_option_pricing');
        $this->assertCount(1, $prices);
        $this->assertEquals(0, $prices[0]['price']);
        $this->assertCount(14, $this->db->select('SELECT id FROM configurable_option_pricing'), '2 options × 7 cycles');

        // ensure() is idempotent.
        (new CloudflareProductOption($this->db, $this->settings))->ensure();
        $this->assertCount(1, $this->db->select('SELECT id FROM configurable_option_groups'));

        // Untick: the product no longer offers it.
        (new CloudflareProductOption($this->db, $this->settings))->syncProducts([]);
        $this->assertFalse($this->service->productEligible(20));
    }

    // ------------------------------------------------------------------ enable

    public function testEnableCreatesZoneCopiesDnsAndFixesOriginRecords(): void
    {
        $this->cf->scanRecords = [
            ['type' => 'A', 'name' => 'mail.example.com', 'content' => '203.0.113.10', 'proxied' => true],
            ['type' => 'MX', 'name' => 'example.com', 'content' => 'mx.example.com', 'priority' => 10],
            ['type' => 'A', 'name' => 'mx.example.com', 'content' => '203.0.113.11', 'proxied' => true],
            ['type' => 'A', 'name' => 'shop.example.com', 'content' => '203.0.113.12', 'proxied' => true],
        ];

        $result = $this->service->enable(100, ['type' => 'client', 'id' => 7]);

        $this->assertTrue($result['ok'], $result['message']);
        $create = $this->cf->find('POST', '/zones');
        $this->assertSame(['name' => 'example.com', 'account' => ['id' => 'acc123'], 'type' => 'full'], json_decode((string) $create['body'], true));
        $this->assertNotNull($this->cf->find('POST', '/dns_records/scan'));

        $zone = $this->zones->liveForService(100);
        $this->assertSame('example.com', $zone['name']);
        $this->assertSame('pending', $zone['status']);
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $zone['name_servers']);
        $this->assertSame(['ns1.oldhost.net', 'ns2.oldhost.net'], $zone['original_name_servers']);
        $this->assertSame(7, (int) $zone['client_id']);
        $this->assertNull($zone['reseller_id']);

        $records = $this->cf->records[$zone['cf_zone_id']];
        $byName = [];
        foreach ($records as $r) {
            $byName[$r['type'] . ' ' . $r['name']] = $r;
        }

        $this->assertSame('203.0.113.10', $byName['A example.com']['content'], 'apex points at the hosting IP');
        $this->assertTrue($byName['A example.com']['proxied']);
        $this->assertSame('example.com', $byName['CNAME www.example.com']['content']);
        $this->assertFalse($byName['A mail.example.com']['proxied'], 'mail must not be proxied');
        $this->assertFalse($byName['A mx.example.com']['proxied'], 'MX targets must not be proxied');
        $this->assertTrue($byName['A shop.example.com']['proxied'], 'other web names stay proxied');

        $this->assertSame('full', $this->cf->settings[$zone['cf_zone_id']]['ssl']);
        $this->assertSame('on', $this->cf->settings[$zone['cf_zone_id']]['always_use_https']);
        $this->assertSame('medium', $this->cf->settings[$zone['cf_zone_id']]['security_level']);

        $actions = array_column($this->zones->activity((int) $zone['id']), 'action');
        $this->assertContains('enabled', $actions);
        $this->assertContains('dns_scan', $actions);

        $again = $this->service->enable(100, ['type' => 'client', 'id' => 7]);
        $this->assertFalse($again['ok']);
        $this->assertCount(1, $this->cf->zones, 'never a second zone for the same service');
    }

    public function testExistingZoneIsNeverAdopted(): void
    {
        $this->cf->zones['f00'] = ['id' => 'f00', 'name' => 'example.com', 'status' => 'active', 'paused' => false, 'name_servers' => []];

        $result = $this->service->enable(100, ['type' => 'client', 'id' => 7]);

        $this->assertFalse($result['ok']);
        $this->assertSame('This domain is already set up in our Cloudflare account.', $result['message']);
        $this->assertNull($this->zones->liveForService(100));
        $this->assertNull($this->cf->find('POST', '/dns_records/scan'));
    }

    public function testDomainAlreadyOnAnotherServiceIsRefusedLocally(): void
    {
        $this->db->exec("INSERT INTO services (id, client_id, order_id, product_id, dedicated_ip, product_name, domain, status) VALUES (101, 8, 501, 20, '203.0.113.20', 'Starter', 'example.com', 'active')");
        $this->assertTrue($this->service->enable(100, ['type' => 'client', 'id' => 7])['ok']);

        $result = $this->service->enable(101, ['type' => 'client', 'id' => 8]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('already using Cloudflare through another service', $result['message']);
        $this->assertCount(1, $this->cf->zones);
    }

    public function testStoreCustomerZoneCarriesTheStoreId(): void
    {
        $this->db->exec("INSERT INTO services (id, client_id, order_id, product_id, dedicated_ip, product_name, domain, status) VALUES (102, 9, 502, 20, '203.0.113.30', 'Starter', 'storeclient.io', 'active')");

        $this->assertTrue($this->service->enable(102, ['type' => 'client', 'id' => 9])['ok']);

        $row = $this->zones->search('live', 'storeclient')[0];
        $this->assertSame(3, (int) $row['reseller_id']);
        $this->assertSame('Acme Hosting', $row['store_name']);
        $this->assertSame(50, (int) $row['store_owner_client_id']);
    }

    // ------------------------------------------------------------------ lifecycle via the hook

    public function testActiveHookEnrolsOnlyWhenChosenAndOnlyOnce(): void
    {
        $this->service->onServiceStatus(100, 'active');
        $this->assertNull($this->zones->liveForService(100), 'not chosen at order → nothing happens');

        $this->db->exec("INSERT INTO order_items (order_id, product_id, configurable_options) VALUES (500, 20, '" . json_encode([$this->settings->optionGroupId() => $this->settings->optionYesId()]) . "')");
        $this->service->onServiceStatus(100, 'active');
        $zone = $this->zones->liveForService(100);
        $this->assertNotNull($zone);

        // Removed later (e.g. client turned it off) — a re-activation must not re-create it.
        $this->assertTrue($this->service->deleteNow((int) $zone['id'], ['type' => 'admin', 'id' => 1])['ok']);
        $this->service->onServiceStatus(100, 'active');
        $this->assertNull($this->zones->liveForService(100));
        $this->assertCount(0, $this->cf->zones);
    }

    public function testAddonHookIsRegisteredForServiceStatus(): void
    {
        $hooks = (new CloudflareAddon())->hooks();
        $this->assertArrayHasKey(HookPoints::SERVICE_STATUS_CHANGED, $hooks);
        // A broken container must never break provisioning.
        App::setContainer(new Container());
        $hooks[HookPoints::SERVICE_STATUS_CHANGED](['serviceId' => 100, 'status' => 'suspended']);
        $this->assertTrue(true);
    }

    public function testSuspendPausesAndUnsuspendResumesOnlyWhatWePaused(): void
    {
        $zone = $this->enabledZone();

        $this->setServiceStatus(100, 'suspended');
        $this->service->onServiceStatus(100, 'suspended');
        $zone = $this->zones->find((int) $zone['id']);
        $this->assertSame(1, (int) $zone['paused']);
        $this->assertSame(1, (int) $zone['paused_by_us']);
        $this->assertTrue($this->cf->zones[$zone['cf_zone_id']]['paused']);

        $this->setServiceStatus(100, 'active');
        $this->service->onServiceStatus(100, 'active');
        $zone = $this->zones->find((int) $zone['id']);
        $this->assertSame(0, (int) $zone['paused']);
        $this->assertFalse($this->cf->zones[$zone['cf_zone_id']]['paused']);

        // A pause the admin chose is left alone when the service is re-activated.
        $this->service->setPaused((int) $zone['id'], true, false, ['type' => 'admin', 'id' => 1]);
        $this->service->onServiceStatus(100, 'active');
        $this->assertTrue($this->cf->zones[$zone['cf_zone_id']]['paused']);
    }

    public function testTerminationRestoresNameserversAndDeletesAfterGracePeriod(): void
    {
        $zone = $this->enabledZone();
        $this->registerDomain(7);
        $this->assertTrue($this->service->switchNameservers((int) $zone['id'], ['type' => 'client', 'id' => 7])['ok']);
        $this->ns->saved = [];

        $this->setServiceStatus(100, 'terminated');
        $this->service->onServiceStatus(100, 'terminated');

        $zone = $this->zones->find((int) $zone['id']);
        $this->assertNotNull($zone['delete_after']);
        $this->assertSame('service_ended', $zone['delete_reason']);
        $days = (strtotime((string) $zone['delete_after']) - time()) / 86400;
        $this->assertTrue($days > 6.9 && $days <= 7.0, 'grace period is 7 days, got ' . $days);
        $this->assertSame([[1, ['ns1.oldhost.net', 'ns2.oldhost.net']]], $this->ns->saved, 'previous nameservers restored at once');
        $this->assertSame(0, (int) $zone['ns_switched_by_us']);

        $cron = $this->cron();
        $this->assertSame(0, $cron->run(time())['deleted'], 'nothing deleted inside the grace period');
        $this->assertArrayHasKey($zone['cf_zone_id'], $this->cf->zones);

        $stats = $cron->run(time() + 8 * 86400);
        $this->assertSame(1, $stats['deleted']);
        $this->assertArrayNotHasKeyCompat($zone['cf_zone_id'], $this->cf->zones);
        $zone = $this->zones->find((int) $zone['id']);
        $this->assertSame('deleted', $zone['status']);
        $this->assertStringContainsString('example.com.', (string) $zone['backup_bind'], 'BIND backup kept');
        $this->assertNull($this->zones->liveForService(100));
    }

    public function testReactivatingWithinGraceKeepsTheZone(): void
    {
        $zone = $this->enabledZone();
        $this->setServiceStatus(100, 'terminated');
        $this->service->onServiceStatus(100, 'terminated');
        $this->assertNotNull($this->zones->find((int) $zone['id'])['delete_after']);

        $this->setServiceStatus(100, 'active');
        $this->service->onServiceStatus(100, 'active');

        $this->assertNull($this->zones->find((int) $zone['id'])['delete_after']);
    }

    public function testClientDisableAndKeep(): void
    {
        $zone = $this->enabledZone();
        $client = ['type' => 'client', 'id' => 7];

        $off = $this->service->scheduleDeletion((int) $zone['id'], 'client', $client);
        $this->assertTrue($off['ok']);
        $this->assertStringContainsString('You can undo this', $off['message']);

        // While removal is pending the client cannot edit, but can keep it.
        $keep = $this->service->cancelDeletion((int) $zone['id'], $client);
        $this->assertTrue($keep['ok']);
        $this->assertNull($this->zones->find((int) $zone['id'])['delete_after']);

        // A client cannot keep Cloudflare on a service that is no longer active.
        $this->service->scheduleDeletion((int) $zone['id'], 'client', $client);
        $this->setServiceStatus(100, 'suspended');
        $this->assertFalse($this->service->cancelDeletion((int) $zone['id'], $client)['ok']);
        $this->assertTrue($this->service->cancelDeletion((int) $zone['id'], ['type' => 'admin', 'id' => 1])['ok'], 'admins can');
    }

    // ------------------------------------------------------------------ nameservers

    public function testSwitchNameserversOnlyForTheClientsOwnRegisteredDomain(): void
    {
        $zone = $this->enabledZone();

        $this->assertFalse($this->service->switchNameservers((int) $zone['id'], ['type' => 'client', 'id' => 7])['ok'], 'not registered with us');

        $this->registerDomain(8); // someone else's registration
        $this->assertFalse($this->service->switchNameservers((int) $zone['id'], ['type' => 'client', 'id' => 7])['ok']);
        $this->assertSame([], $this->ns->saved);

        $this->db->exec('UPDATE domains SET client_id = 7');
        $this->ns->current = ['ns1.mine.net', 'ns2.mine.net'];
        $result = $this->service->switchNameservers((int) $zone['id'], ['type' => 'client', 'id' => 7]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame([[1, ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com']]], $this->ns->saved);
        $zone = $this->zones->find((int) $zone['id']);
        $this->assertSame(1, (int) $zone['ns_switched_by_us']);
        $this->assertSame(['ns1.mine.net', 'ns2.mine.net'], $zone['original_name_servers'], 'what the registrar had is the rollback target');
        $this->assertNotNull($this->cf->find('PUT', '/activation_check'));
    }

    public function testRegistrarFailureIsReportedAndNothingIsMarked(): void
    {
        $zone = $this->enabledZone();
        $this->registerDomain(7);
        $this->ns->fail = 'Domain is locked';

        $result = $this->service->switchNameservers((int) $zone['id'], ['type' => 'client', 'id' => 7]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Domain is locked', $result['message']);
        $this->assertSame(0, (int) $this->zones->find((int) $zone['id'])['ns_switched_by_us']);
    }

    // ------------------------------------------------------------------ cron & reconcile

    public function testActivationIsDetectedAndRemindersGoOutOnDays3And7(): void
    {
        $zone = $this->enabledZone();
        $cron = $this->cron();
        $created = strtotime((string) $zone['created_at']);

        $cron->run($created + 3600 * 2);
        $this->assertSame(0, (int) $this->zones->find((int) $zone['id'])['reminders_sent']);

        $this->db->exec("UPDATE cloudflare_zones SET last_checked_at = NULL");
        $this->assertSame(1, $cron->run($created + 3 * 86400 + 60)['reminded']);
        $this->db->exec("UPDATE cloudflare_zones SET last_checked_at = NULL");
        $this->assertSame(0, $cron->run($created + 4 * 86400)['reminded'], 'one reminder per milestone');
        $this->db->exec("UPDATE cloudflare_zones SET last_checked_at = NULL");
        $this->assertSame(1, $cron->run($created + 7 * 86400 + 60)['reminded']);

        $this->cf->zones[$zone['cf_zone_id']]['status'] = 'active';
        $result = $this->service->checkActivation((int) $zone['id'], ['type' => 'client', 'id' => 7]);
        $this->assertStringContainsString('is active on Cloudflare', $result['message']);
        $zone = $this->zones->find((int) $zone['id']);
        $this->assertSame('active', $zone['status']);
        $this->assertNotNull($zone['activated_at']);
        $this->assertContains('activated', array_column($this->zones->activity((int) $zone['id']), 'action'));
    }

    public function testReconcileCatchesChangesMadeWithoutTheHook(): void
    {
        $zone = $this->enabledZone();

        // Suspended directly in the database (no hook fired).
        $this->setServiceStatus(100, 'suspended');
        $this->service->reconcile((int) $zone['id']);
        $this->assertSame(1, (int) $this->zones->find((int) $zone['id'])['paused_by_us']);

        // Terminated the same way.
        $this->setServiceStatus(100, 'terminated');
        $this->service->reconcile((int) $zone['id']);
        $this->assertNotNull($this->zones->find((int) $zone['id'])['delete_after']);

        // Zone removed in the Cloudflare dashboard (or expired while pending).
        unset($this->cf->zones[$zone['cf_zone_id']]);
        $this->service->reconcile((int) $zone['id']);
        $this->assertSame('deleted', $this->zones->find((int) $zone['id'])['status']);
    }

    public function testCronIsANoOpWhileInactive(): void
    {
        $this->enabledZone();
        $this->db->exec("UPDATE addon_modules SET enabled = 0");
        $before = count($this->cf->requests);

        $this->cron()->handle();

        $this->assertSame($before, count($this->cf->requests));
    }

    // ------------------------------------------------------------------ client tools

    public function testDnsCrudThroughTheService(): void
    {
        $zone = $this->enabledZone();
        $actor = ['type' => 'client', 'id' => 7];

        $this->assertTrue($this->service->saveDns($zone, ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 a mx ~all'], null, $actor)['ok']);
        $txt = array_values(array_filter($this->service->dnsRecords($zone)['records'], static fn ($r) => $r['type'] === 'TXT'))[0];

        $this->assertTrue($this->service->saveDns($zone, ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 -all'], $txt['id'], $actor)['ok']);
        $this->assertSame('v=spf1 -all', $this->cf->records[$zone['cf_zone_id']][$txt['id']]['content']);

        $this->assertTrue($this->service->deleteDns($zone, $txt['id'], $actor)['ok']);
        $this->assertArrayNotHasKeyCompat($txt['id'], $this->cf->records[$zone['cf_zone_id']]);
        $this->assertFalse($this->service->deleteDns($zone, 'nope', $actor)['ok']);

        // Unsupported types are delete-only.
        $this->cf->records[$zone['cf_zone_id']]['srv1'] = ['id' => 'srv1', 'type' => 'SRV', 'name' => '_sip._tcp.example.com', 'content' => '1 1 5060 sip.example.com', 'ttl' => 1];
        $this->assertFalse($this->service->saveDns($zone, ['type' => 'A', 'name' => 'x', 'content' => '198.51.100.1'], 'srv1', $actor)['ok']);

        $this->assertContains('dns_created', array_column($this->zones->activity((int) $zone['id']), 'action'));
    }

    public function testSettingsPurgeAndAccessRulesAreValidated(): void
    {
        $zone = $this->enabledZone();
        $actor = ['type' => 'client', 'id' => 7];

        $this->assertTrue($this->service->changeSetting($zone, 'security_level', 'under_attack', $actor)['ok']);
        $this->assertSame('under_attack', $this->cf->settings[$zone['cf_zone_id']]['security_level']);
        $this->assertTrue($this->service->changeSetting($zone, 'browser_cache_ttl', '14400', $actor)['ok']);
        $this->assertSame(14400, $this->cf->settings[$zone['cf_zone_id']]['browser_cache_ttl'], 'sent as an integer');
        $this->assertFalse($this->service->changeSetting($zone, 'ssl', 'super', $actor)['ok']);
        $this->assertFalse($this->service->changeSetting($zone, 'plan', 'pro', $actor)['ok'], 'no setting outside the allow-list');

        $this->assertTrue($this->service->purge($zone, null, $actor)['ok']);
        $this->assertTrue($this->service->purge($zone, ['https://example.com/a.css', 'https://www.example.com/b.js'], $actor)['ok']);
        $this->assertFalse($this->service->purge($zone, ['https://evil.com/a.css'], $actor)['ok'], 'only URLs on the zone');
        $this->assertFalse($this->service->purge($zone, ['ftp://example.com/x'], $actor)['ok']);

        $this->assertTrue($this->service->addAccessRule($zone, 'block', 'ip', '198.51.100.9', 'spam', $actor)['ok']);
        $this->assertTrue($this->service->addAccessRule($zone, 'managed_challenge', 'country', 'ng', '', $actor)['ok']);
        $this->assertTrue($this->service->addAccessRule($zone, 'block', 'asn', '13335', '', $actor)['ok']);
        $this->assertFalse($this->service->addAccessRule($zone, 'block', 'ip_range', '10.0.0.0/8', '', $actor)['ok'], 'Cloudflare accepts /16 and /24 only');
        $this->assertFalse($this->service->addAccessRule($zone, 'allow_all', 'ip', '198.51.100.9', '', $actor)['ok']);

        $rules = $this->service->accessRules($zone)['rules'];
        $this->assertSame(['198.51.100.9', 'NG', 'AS13335'], array_map(static fn ($r) => $r['configuration']['value'], $rules));
        $this->assertTrue($this->service->deleteAccessRule($zone, (string) $rules[0]['id'], $actor)['ok']);
        $this->assertCount(2, $this->service->accessRules($zone)['rules']);
    }

    public function testStateForDrivesTheServicePage(): void
    {
        $services = new ServiceRepository($this->db);
        $state = $this->service->stateFor($services->find(100));
        $this->assertTrue($state['show']);
        $this->assertTrue($state['canEnable']);

        $this->settings->save(['allow_later' => '0']);
        $this->assertFalse($this->service->stateFor($services->find(100))['show'], 'order-time only');
        $this->settings->save(['allow_later' => '1']);

        $this->setServiceStatus(100, 'terminated');
        $this->assertFalse($this->service->stateFor($services->find(100))['show'], 'nothing to offer on an ended service');

        $this->setServiceStatus(100, 'pending');
        $state = $this->service->stateFor($services->find(100));
        $this->assertFalse($state['canEnable']);

        $this->db->exec("INSERT INTO services (id, client_id, order_id, product_id, dedicated_ip, product_name, domain, status) VALUES (103, 7, 503, 21, NULL, 'VPS', 'vps.example.org', 'active')");
        $this->assertFalse($this->service->stateFor($services->find(103))['show'], 'product not offered Cloudflare');

        $this->settings->saveToken('');
        $this->assertFalse($this->service->stateFor($services->find(100))['show'], 'hidden until connected');
    }

    // ------------------------------------------------------------------ views

    public function testClientAndAdminViewsRender(): void
    {
        $this->bootContainer();
        $view = new View('/home/user/WHMP/resources/views');
        $services = new ServiceRepository($this->db);
        $zone = $this->enabledZone();
        $service = $services->find(100);
        $base = ['service' => $service, 'state' => $this->service->stateFor($service), 'notice' => 'Saved.', 'error' => null, 'registered' => true,
            'manageable' => true, 'records' => [], 'settings' => [], 'rules' => [], 'activity' => [], 'loadError' => null, 'editId' => ''];

        $html = $view->render('cloudflare.client', ['zone' => $zone, 'tab' => 'overview'] + $base);
        $this->assertStringContainsString('ada.ns.cloudflare.com', $html);
        $this->assertStringContainsString('Switch nameservers for me', $html);
        $this->assertStringContainsString('Waiting for nameservers', $html);
        $this->assertStringContainsString('name="_token"', $html);

        $html = $view->render('cloudflare.client', ['zone' => $zone, 'tab' => 'overview', 'registered' => false] + $base);
        $this->assertStringContainsString('registrar', $html);
        $this->assertStringNotContainsString('Switch nameservers for me', $html);

        $records = $this->service->dnsRecords($zone)['records'];
        $html = $view->render('cloudflare.client', ['zone' => $zone, 'tab' => 'dns', 'records' => $records] + $base);
        $this->assertStringContainsString('Add a DNS record', $html);
        $this->assertStringContainsString('Proxied', $html);

        $settings = $this->service->zoneSettings($zone)['settings'];
        foreach (['ssl', 'speed', 'caching', 'rules', 'security', 'analytics', 'activity'] as $tab) {
            $html = $view->render('cloudflare.client', ['zone' => $zone, 'tab' => $tab, 'settings' => $settings, 'activity' => $this->zones->activity((int) $zone['id'])] + $base);
            $this->assertStringContainsString('cv-tab', $html);
        }
        $html=$view->render('cloudflare.client',['zone'=>$zone,'tab'=>'speed','settings'=>$settings]+$base);
        $this->assertStringContainsString('Early Hints',$html);
        $features=$this->features();
        $html=$view->render('cloudflare.client',['zone'=>$zone,'tab'=>'dns','dnssec'=>$features->dnssec($zone)]+$base);
        $this->assertStringContainsString('DNSSEC',$html);
        $html=$view->render('cloudflare.client',['zone'=>$zone,'tab'=>'rules','rulesets'=>$features->rules($zone)]+$base);
        $this->assertStringContainsString('Redirect rules',$html);
        $html=$view->render('cloudflare.client',['zone'=>$zone,'tab'=>'analytics','analytics'=>$features->analytics($zone,7),'range'=>7]+$base);
        $this->assertStringContainsString('Top countries',$html);

        $html = $view->render('cloudflare.client', ['zone' => null, 'tab' => 'overview', 'state' => ['show' => true, 'canEnable' => true, 'zone' => null, 'reason' => '']] + $base);
        $this->assertStringContainsString('Enable free Cloudflare', $html);

        foreach (['cloudflare.client'] as $tpl) {
            $html = $view->render($tpl, ['zone' => $zone, 'tab' => 'overview'] + $base);
            $this->assertStringNotContainsString('Hosting Co', $html, 'the platform\'s Cloudflare account is never shown to clients');
        }

        $html = $view->render('cloudflare.admin-index', ['counts' => $this->zones->counts(), 'rows' => $this->zones->search('live', ''), 'status' => 'live', 'q' => '', 'pageNo' => 1, 'connected' => true, 'accountName' => 'Hosting Co', 'productCount' => 1]);
        $this->assertStringContainsString('example.com', $html);
        $this->assertStringContainsString('/admin/cloudflare/zones/' . $zone['id'], $html);

        $html = $view->render('cloudflare.admin-zone', ['zone' => $this->zones->detailed((int) $zone['id']), 'activity' => $this->zones->activity((int) $zone['id']), 'graceDays' => 7]);
        $this->assertStringContainsString('Schedule removal (7 days)', $html);
        $this->assertStringContainsString('/admin/clients/7', $html);

        $option = new CloudflareProductOption($this->db, $this->settings);
        $html = $view->render('cloudflare.admin-settings', ['settings' => $this->settings, 'hasToken' => true, 'accounts' => [], 'products' => $option->products(), 'attached' => $option->attachedProductIds()]);
        $this->assertStringContainsString('Verify &amp; save', $html);
        $this->assertStringContainsString('Account Filter Lists', $html, 'the extra Cache Rules permission is documented');
        $this->assertStringNotContainsString('tok_test', $html, 'the token is never rendered');
    }

    public function testStoreCustomersAreNeverLinkedToTheGeneralClientArea(): void
    {
        $this->bootContainer();
        $this->db->exec("INSERT INTO services (id, client_id, order_id, product_id, dedicated_ip, product_name, domain, status) VALUES (102, 9, 502, 20, '203.0.113.30', 'Starter', 'storeclient.io', 'active')");
        $this->service->enable(102, ['type' => 'client', 'id' => 9]);
        $zone = $this->zones->liveForService(102);
        $view = new View('/home/user/WHMP/resources/views');

        $html = $view->render('cloudflare.admin-zone', ['zone' => $this->zones->detailed((int) $zone['id']), 'activity' => [], 'graceDays' => 7]);

        $this->assertStringNotContainsString('/admin/clients/9', $html);
        $this->assertStringContainsString('/admin/resellers/50/customers/9', $html);
        $this->assertStringContainsString('Acme Hosting', $html);
    }

    public function testServiceCardRendersOnlyWhenOffered(): void
    {
        $this->bootContainer();
        $view = new View('/home/user/WHMP/resources/views');
        $service = (new ServiceRepository($this->db))->find(100);

        $this->assertStringContainsString('Set up free Cloudflare', $view->render('cloudflare.service-card', ['service' => $service]));

        $this->db->exec("UPDATE addon_modules SET enabled = 0");
        $this->assertSame('', trim($view->render('cloudflare.service-card', ['service' => $service])));
    }

    public function testRulesetsUseSafeConditionsAndFreePlanLimits(): void
    {
        $zone=$this->enabledZone(); $features=$this->features(); $actor=['type'=>'client','id'=>7];
        $added=$features->addPreset($zone,'www_to_apex',$actor);
        $this->assertTrue($added['ok'],$added['message']);
        $set=$features->rules($zone);
        $this->assertCount(1,$set['redirect']['rules']);
        $rule=$set['redirect']['rules'][0];
        $this->assertSame('redirect',$rule['action']);
        $found=false;
        foreach($this->cf->requests as $request){
            if($request['method']==='POST' && str_ends_with($request['url'],'/rulesets')) {
                $body=(array)json_decode((string)$request['body'],true);
                if(($body['phase']??'')==='http_request_dynamic_redirect'){$found=true;break;}
            }
        }
        $this->assertTrue($found,'new redirect entry points are created as zone Rulesets with the correct phase');
        $cache=$features->addRule($zone,'cache',['match'=>'extension','value'=>'jpg,png','cache_mode'=>'cache','edge_ttl'=>'86400'],$actor);
        $this->assertTrue($cache['ok'],$cache['message']);
        $fw=$features->addRule($zone,'firewall',['match'=>'country','value'=>'NG','action'=>'managed_challenge'],$actor);
        $this->assertTrue($fw['ok'],$fw['message']);
        $this->assertTrue($features->deleteRule($zone,'redirect',(string)$rule['id'],$actor)['ok']);
        for($i=0;$i<10;$i++) {
            $r=$features->addRule($zone,'redirect',['match'=>'path','value'=>'/old'.$i,'target_url'=>'https://example.com/new'.$i],$actor);
            $this->assertTrue($r['ok'],$r['message']);
        }
        $over=$features->addRule($zone,'redirect',['match'=>'path','value'=>'/extra','target_url'=>'https://example.com/new-extra'],$actor);
        $this->assertFalse($over['ok']);
        $this->assertStringContainsString('Free plan allows 10',$over['message']);
        $this->assertNull(CloudflareRules::build('firewall',['match'=>'path','value'=>'/ok" or true','action'=>'block'],'example.com',$error));
        $this->assertStringContainsString('not allowed',$error);
        $loop=CloudflareRules::build('redirect',['match'=>'all','target_url'=>'https://example.com','keep_path'=>'1'],'example.com',$error);
        $this->assertNull($loop);
        $this->assertStringContainsString('loop',$error);
        $credentialUrl=CloudflareRules::build('redirect',['match'=>'path','value'=>'/old','target_url'=>'https://user:pass@outside.example/new'],'example.com',$error);
        $this->assertNull($credentialUrl,'redirect URLs must not carry embedded credentials');
    }

    public function testDnssecAutomationAndDeferredDisable(): void
    {
        $zone=$this->enabledZone(); $this->activateZone($zone); $this->registerDomain(7);
        $features=$this->features(); $actor=['type'=>'client','id'=>7];
        $on=$features->enableDnssec($this->zones->find((int)$zone['id']),$actor);
        $this->assertTrue($on['ok'],$on['message']);
        $fresh=$this->zones->find((int)$zone['id']);
        $this->assertSame(1,(int)$fresh['dnssec_ds_by_us']);
        $this->assertSame('add',$this->ns->dsCalls[0][0]);
        $off=$features->disableDnssec($fresh,false,$actor);
        $this->assertTrue($off['ok'],$off['message']);
        $this->assertSame('pending-disabled',$this->zones->find((int)$zone['id'])['dnssec_status']);
        $this->assertSame('remove',$this->ns->dsCalls[1][0]);
        $this->assertSame('pending-disabled',$features->dnssec($this->zones->find((int)$zone['id']))['status'],'a page refresh must not erase the scheduled safe-disable state');
        $this->assertFalse($features->disableDnssec($this->zones->find((int)$zone['id']),true,$actor)['ok'],'clients cannot bypass the DS cache wait');
        $this->assertFalse($features->finishDnssecDisable((int)$zone['id'])['ok'],'DNSSEC stays enabled during DS cache expiry');
        $this->db->exec("UPDATE cloudflare_zones SET dnssec_disable_after=datetime('now','-1 second') WHERE id=".(int)$zone['id']);
        $finished=$features->finishDnssecDisable((int)$zone['id']);
        $this->assertTrue($finished['ok'],$finished['message']);
        $this->assertSame('disabled',$this->zones->find((int)$zone['id'])['dnssec_status']);
    }

    public function testExternalDsBlocksUnsafeNameserverRestoreAndZoneDelete(): void
    {
        $zone=$this->enabledZone(); $this->activateZone($zone); $this->registerDomain(7); $this->ns->dsSupported=false;
        $features=$this->features(); $actor=['type'=>'client','id'=>7];
        $on=$features->enableDnssec($this->zones->find((int)$zone['id']),$actor);
        $this->assertTrue($on['ok'],$on['message']);
        $scheduled=$this->service->scheduleDeletion((int)$zone['id'],'client',$actor);
        $this->assertTrue($scheduled['ok'],$scheduled['message']);
        $this->assertStringContainsString('nameservers are unchanged',$scheduled['message']);
        $del=$this->service->deleteNow((int)$zone['id'],['type'=>'admin','id'=>1]);
        $this->assertFalse($del['ok']);
        $this->assertArrayHasKey($zone['cf_zone_id'],$this->cf->zones);
        $manual=$features->disableDnssec($this->zones->find((int)$zone['id']),true,$actor);
        $this->assertTrue($manual['ok'],$manual['message']);
        $this->assertSame('pending-disabled',$this->zones->find((int)$zone['id'])['dnssec_status']);
        $this->assertFalse($this->service->deleteNow((int)$zone['id'],['type'=>'admin','id'=>1])['ok']);
        $this->assertArrayHasKey($zone['cf_zone_id'],$this->cf->zones);
    }

    public function testOutOfBandDnssecDisableKeepsExternalDsCleanupPending(): void
    {
        $zone=$this->enabledZone(); $this->activateZone($zone); $this->registerDomain(7); $this->ns->dsSupported=false;
        $features=$this->features(); $actor=['type'=>'client','id'=>7];
        $this->assertTrue($features->enableDnssec($this->zones->find((int)$zone['id']),$actor)['ok']);
        $this->cf->dnssec[(string)$zone['cf_zone_id']]=['status'=>'disabled'];
        $dnssec=$features->dnssec($this->zones->find((int)$zone['id']));
        $this->assertSame('pending-disabled',$dnssec['status']);
        $this->assertIsArray($dnssec['ds']);

        $scheduled=$this->service->scheduleDeletion((int)$zone['id'],'client',$actor);
        $this->assertTrue($scheduled['ok'],$scheduled['message']);
        $this->assertStringContainsString('nameservers are unchanged',$scheduled['message']);
        $blocked=$this->service->deleteNow((int)$zone['id'],['type'=>'admin','id'=>1]);
        $this->assertFalse($blocked['ok']);
        $confirmed=$features->disableDnssec($this->zones->find((int)$zone['id']),true,$actor);
        $this->assertTrue($confirmed['ok'],$confirmed['message']);
        $this->assertSame(1,(int)$this->zones->find((int)$zone['id'])['dnssec_ds_removed']);
    }

    public function testRemovalRefreshesOutOfBandDnssecAndFailsClosedOnApiErrors(): void
    {
        $zone=$this->enabledZone(); $this->activateZone($zone); $this->registerDomain(7); $actor=['type'=>'client','id'=>7];
        $this->assertTrue($this->service->switchNameservers((int)$zone['id'],$actor)['ok']); $this->ns->saved=[];
        $this->cf->dnssec[(string)$zone['cf_zone_id']]=['status'=>'active','key_tag'=>2371,'algorithm'=>13,'digest_type'=>2,'digest'=>str_repeat('A1B2C3D4',8),'ds'=>'2371 13 2 '.str_repeat('A1B2C3D4',8)];
        $scheduled=$this->service->scheduleDeletion((int)$zone['id'],'client',$actor);
        $this->assertTrue($scheduled['ok'],$scheduled['message']);
        $this->assertSame([],$this->ns->saved,'DNSSEC enabled outside WHMP keeps nameservers on Cloudflare');
        $this->assertSame('active',$this->zones->find((int)$zone['id'])['dnssec_status']);
        $this->assertFalse($this->service->deleteNow((int)$zone['id'],['type'=>'admin','id'=>1])['ok']);

        $this->assertTrue($this->service->cancelDeletion((int)$zone['id'],$actor)['ok']);
        $this->cf->failDnssecGet=true;
        $scheduled=$this->service->scheduleDeletion((int)$zone['id'],'client',$actor);
        $this->assertTrue($scheduled['ok'],$scheduled['message']);
        $this->assertSame([],$this->ns->saved,'an API permission/network failure must fail closed');
        $this->assertFalse($this->service->deleteNow((int)$zone['id'],['type'=>'admin','id'=>1])['ok']);
    }

    public function testExistingDnssecWaitStillDefersRestoreWithStaleStatusAndApiFailure(): void
    {
        $zone=$this->enabledZone(); $this->activateZone($zone); $this->registerDomain(7); $actor=['type'=>'client','id'=>7];
        $this->assertTrue($this->service->switchNameservers((int)$zone['id'],$actor)['ok']); $this->ns->saved=[];
        $features=$this->features();
        $this->assertTrue($features->enableDnssec($this->zones->find((int)$zone['id']),$actor)['ok']);
        $this->assertTrue($features->disableDnssec($this->zones->find((int)$zone['id']),false,$actor)['ok']);
        $waiting=$this->zones->find((int)$zone['id']);
        $this->assertSame(1,(int)$waiting['dnssec_ds_removed']);
        $this->zones->update((int)$zone['id'],['dnssec_status'=>'active']); // stale status from a prior sync
        $this->cf->failDnssecGet=true;

        $scheduled=$this->service->scheduleDeletion((int)$zone['id'],'client',$actor);
        $this->assertTrue($scheduled['ok'],$scheduled['message']);
        $fresh=$this->zones->find((int)$zone['id']);
        $this->assertSame($waiting['dnssec_disable_after'],$fresh['ns_restore_after']);
        $this->assertSame([],$this->ns->saved,'stale status/API failure cannot bypass an existing DNSSEC wait');
    }

    public function testDnssecDefersNameserverRestoreForFortyEightHours(): void
    {
        $zone=$this->enabledZone(); $this->activateZone($zone); $this->registerDomain(7); $actor=['type'=>'client','id'=>7];
        $this->assertTrue($this->service->switchNameservers((int)$zone['id'],$actor)['ok']); $this->ns->saved=[];
        $features=$this->features();
        $this->assertTrue($features->enableDnssec($this->zones->find((int)$zone['id']),$actor)['ok']);
        $off=$this->service->scheduleDeletion((int)$zone['id'],'client',$actor);
        $this->assertTrue($off['ok'],$off['message']);
        $this->assertNotNull($this->zones->find((int)$zone['id'])['ns_restore_after']);
        $this->assertSame([],$this->ns->saved,'old nameservers are not restored while a DS record may be cached');
        $this->db->exec("UPDATE cloudflare_zones SET ns_restore_after=datetime('now','-1 second') WHERE id=".(int)$zone['id']);
        $stats=$this->cron($features)->run(time());
        $this->assertSame(1,$stats['restored']);
        $this->assertSame([[1,['ns1.oldhost.net','ns2.oldhost.net']]],$this->ns->saved);
    }

    public function testAnalyticsReadsDailyCloudflareGroups(): void
    {
        $zone=$this->enabledZone(); $today=gmdate('Y-m-d'); $yesterday=gmdate('Y-m-d',time()-86400);
        $this->cf->trafficGroups=[
            ['dimensions'=>['date'=>$yesterday],'sum'=>['requests'=>100,'cachedRequests'=>60,'bytes'=>10000,'cachedBytes'=>6000,'threats'=>2,'pageViews'=>40,'countryMap'=>[['clientCountryName'=>'NG','requests'=>70],['clientCountryName'=>'US','requests'=>30]]],'uniq'=>['uniques'=>20]],
            ['dimensions'=>['date'=>$today],'sum'=>['requests'=>50,'cachedRequests'=>20,'bytes'=>5000,'cachedBytes'=>2000,'threats'=>1,'pageViews'=>15,'countryMap'=>[['clientCountryName'=>'NG','requests'=>50]]],'uniq'=>['uniques'=>10]],
        ];
        $result=$this->features()->analytics($zone,7);
        $this->assertTrue($result['ok'],$result['message']);
        $this->assertSame(150,$result['totals']['requests']);
        $this->assertSame(80,$result['totals']['cached']);
        $this->assertSame(['NG'=>120,'US'=>30],$result['countries']);
        $this->assertStringContainsString('/graphql',end($this->cf->requests)['url']);
    }

    public function testOriginCertificateUsesInstallerAndIsRevokedWithZone(): void
    {
        $zone=$this->enabledZone(); $this->activateZone($zone); $installer=new FakeOriginInstaller(); $features=$this->features($installer,new FakeOriginKeyGenerator());
        $result=$features->installOriginCertificate($this->zones->find((int)$zone['id']),true,['type'=>'client','id'=>7]);
        $this->assertTrue($result['ok'],$result['message']);
        $this->assertCount(1,$installer->installed);
        $this->assertSame('example.com',$installer->installed[0]['domain']);
        $originRequest=$this->cf->find('POST','/certificates');
        $originBody=(array)json_decode((string)$originRequest['body'],true);
        $this->assertSame(5475,$originBody['requested_validity'],'Cloudflare accepts the documented 15-year Origin CA validity');
        $this->assertSame(['example.com','*.example.com'],$originBody['hostnames']);
        $this->assertStringContainsString('PRIVATE KEY',$installer->installed[0]['privateKey']);
        $fresh=$this->zones->find((int)$zone['id']); $this->assertNotNull($fresh['origin_cert_id']);
        $this->assertSame('strict',$this->cf->settings[$zone['cf_zone_id']]['ssl']);
        $this->assertArrayNotHasKey('private_key',$fresh);
        $this->assertTrue($this->service->deleteNow((int)$zone['id'],['type'=>'admin','id'=>1])['ok']);
        $this->assertArrayNotHasKey($fresh['origin_cert_id'],$this->cf->certificates);
    }

    public function testPhaseTwoZoneFieldsPersistDecodeAndMatchBaseSchema(): void
    {
        $zone=$this->enabledZone();
        $ds=['key_tag'=>2371,'algorithm'=>13,'digest_type'=>2,'digest'=>str_repeat('A1B2C3D4',8),'ds'=>'2371 13 2 '.str_repeat('A1B2C3D4',8)];
        $this->zones->update((int)$zone['id'],[
            'dnssec_status'=>'active','dnssec_ds'=>$ds,'dnssec_ds_by_us'=>true,'dnssec_ds_removed'=>false,
            'ns_restore_after'=>'2026-10-10 12:00:00','dnssec_disable_after'=>'2026-10-10 12:00:00',
            'origin_cert_id'=>'cert123','origin_cert_expires'=>'2041-10-08 12:00:00',
        ]);
        $fresh=$this->zones->find((int)$zone['id']);
        $this->assertSame('active',$fresh['dnssec_status']);
        $this->assertSame($ds,$fresh['dnssec_ds']);
        $this->assertSame(1,(int)$fresh['dnssec_ds_by_us']);
        $this->assertSame(0,(int)$fresh['dnssec_ds_removed']);
        $this->assertSame('2026-10-10 12:00:00',$fresh['ns_restore_after']);
        $this->assertSame('cert123',$fresh['origin_cert_id']);
        $this->assertSame('2041-10-08 12:00:00',$fresh['origin_cert_expires']);

        $root=dirname(__DIR__,2);
        $schema=require $root.'/database/schema.php';
        $columns=$schema['tables']['cloudflare_zones']['columns'];
        $required=['dnssec_status','dnssec_ds','dnssec_ds_by_us','dnssec_ds_removed','ns_restore_after','dnssec_disable_after','origin_cert_id','origin_cert_expires'];
        $migration=(string)file_get_contents($root.'/database/migrations/0217_cloudflare_phase2.php');
        foreach($required as $column) {
            $this->assertArrayHasKey($column,$columns);
            $this->assertStringContainsString("'".$column."' => '".$column." ",$migration,'migration includes '.$column);
        }
    }

    public function testTemplatesSeedWithoutOverwriting(): void
    {
        CloudflareTemplates::ensure($this->db);
        $this->db->exec("UPDATE email_templates SET subject = 'Edited' WHERE `key` = 'cloudflare_zone_active'");
        CloudflareTemplates::ensure($this->db);

        $this->assertCount(4, $this->db->select('SELECT id FROM email_templates'));
        $this->assertSame('Edited', $this->db->selectOne("SELECT subject FROM email_templates WHERE `key` = 'cloudflare_zone_active'")['subject']);
        foreach (CloudflareTemplates::all() as $template) {
            $this->assertStringNotContainsString('CodeVault', $template['body'], 'templates never hard-code the platform name');
        }
    }

    // ------------------------------------------------------------------ helpers

    private function features(?FakeOriginInstaller $installer=null, ?FakeOriginKeyGenerator $keygen=null): CloudflareFeatures
    {
        return new CloudflareFeatures($this->service,$this->zones,$this->ns,$installer,$keygen);
    }

    private function activateZone(array $zone): void
    {
        $this->zones->update((int)$zone['id'],['status'=>'active']);
        $this->cf->zones[(string)$zone['cf_zone_id']]['status']='active';
    }

    /** @return array<string, mixed> */
    private function enabledZone(): array
    {
        $result = $this->service->enable(100, ['type' => 'client', 'id' => 7]);
        $this->assertTrue($result['ok'], $result['message']);

        return $this->zones->liveForService(100);
    }

    private function registerDomain(int $clientId): void
    {
        $this->db->exec("INSERT INTO domains (id, client_id, domain_name, status, nameservers) VALUES (1, {$clientId}, 'example.com', 'active', '[\"ns1.oldhost.net\",\"ns2.oldhost.net\"]')");
    }

    private function setServiceStatus(int $id, string $status): void
    {
        $this->db->exec("UPDATE services SET status = '{$status}' WHERE id = {$id}");
    }

    private function cron(?CloudflareFeatures $features=null): CloudflareCronJob
    {
        return new CloudflareCronJob(new AddonModuleRepository($this->db),$this->settings,$this->zones,$this->service,new ServiceRepository($this->db),null,$features);
    }

    private function bootContainer(): void
    {
        $config = new Config(sys_get_temp_dir() . '/cv-cf-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        $container->instance(Database::class, $this->db);
        $container->instance(AddonModuleRepository::class, new AddonModuleRepository($this->db));
        $container->instance(CloudflareService::class, $this->service);
        App::setContainer($container);
    }

    /** @param array<mixed> $array */
    private function assertArrayNotHasKeyCompat(string $key, array $array): void
    {
        $this->assertFalse(array_key_exists($key, $array), "Key {$key} should be gone");
    }
}

/** Database over in-memory SQLite, translating the few MySQL-only bits used here. */
final class CfSqliteDatabase extends Database
{
    private PDO $pdo;

    public function __construct()
    {
        parent::__construct('', '', '', '', '');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT, updated_at TEXT);
            CREATE TABLE currencies (id INTEGER PRIMARY KEY, code TEXT, symbol TEXT, exchange_rate REAL, is_default INT);
            CREATE TABLE clients (id INTEGER PRIMARY KEY, reseller_id INT NULL, email TEXT, first_name TEXT, last_name TEXT, company_name TEXT, currency_id INT NULL);
            CREATE TABLE resellers (id INTEGER PRIMARY KEY, client_id INT, brand_name TEXT, slug TEXT);
            CREATE TABLE services (id INTEGER PRIMARY KEY, client_id INT, order_id INT NULL, product_id INT, server_id INT NULL, dedicated_ip TEXT NULL, product_name TEXT, domain TEXT, status TEXT, username TEXT NULL);
            CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, type TEXT, status TEXT);
            CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INT, product_id INT, configurable_options TEXT);
            CREATE TABLE configurable_option_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE configurable_options (id INTEGER PRIMARY KEY AUTOINCREMENT, option_group_id INT, name TEXT, sort_order INT, created_at TEXT, updated_at TEXT);
            CREATE TABLE configurable_option_pricing (id INTEGER PRIMARY KEY AUTOINCREMENT, option_id INT, billing_cycle TEXT, price REAL);
            CREATE TABLE product_configurable_option_groups (product_id INT, option_group_id INT);
            CREATE TABLE domains (id INTEGER PRIMARY KEY, client_id INT, domain_name TEXT, status TEXT, nameservers TEXT, registrar_slug TEXT NULL);
            CREATE TABLE email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE, name TEXT, subject TEXT, body_html TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE addon_modules (slug TEXT PRIMARY KEY, enabled INT);
            CREATE TABLE cloudflare_zones (
                id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INT NULL, client_id INT NOT NULL, reseller_id INT NULL,
                cf_zone_id TEXT NOT NULL UNIQUE, name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
                name_servers TEXT NULL, original_name_servers TEXT NULL, ns_switched_by_us INT NOT NULL DEFAULT 0,
                paused INT NOT NULL DEFAULT 0, paused_by_us INT NOT NULL DEFAULT 0, delete_after TEXT NULL, delete_reason TEXT NULL,
                backup_bind TEXT NULL, last_error TEXT NULL, reminders_sent INT NOT NULL DEFAULT 0, activated_at TEXT NULL,
                last_checked_at TEXT NULL, last_synced_at TEXT NULL, deleted_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                dnssec_status TEXT NULL, dnssec_ds TEXT NULL, dnssec_ds_by_us INT NOT NULL DEFAULT 0, dnssec_ds_removed INT NOT NULL DEFAULT 0,
                ns_restore_after TEXT NULL, dnssec_disable_after TEXT NULL, origin_cert_id TEXT NULL, origin_cert_expires TEXT NULL
            );
            CREATE TABLE cloudflare_activity (id INTEGER PRIMARY KEY AUTOINCREMENT, zone_id INT NOT NULL, actor_type TEXT NOT NULL, actor_id INT NULL, action TEXT NOT NULL, summary TEXT NOT NULL, created_at TEXT NOT NULL);
            INSERT INTO products (id, name, type, status) VALUES (20, 'Starter', 'shared', 'active'), (21, 'VPS', 'vps', 'active');
            SQL);
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function statement(string $sql, array $bindings = []): \PDOStatement
    {
        $sql = str_replace(['INSERT IGNORE', 'NOW()'], ['INSERT OR IGNORE', 'CURRENT_TIMESTAMP'], $sql);
        $sql = (string) preg_replace('/ON DUPLICATE KEY UPDATE `value` = VALUES\(`value`\), updated_at = VALUES\(updated_at\)/', 'ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`, updated_at = excluded.updated_at', $sql);

        return parent::statement($sql, $bindings);
    }
}

/** NameserverGateway that records what the registrar was asked to do. */
final class FakeNameserverGateway implements NameserverGateway
{
    /** @var array<int, array{0: int, 1: array<int, string>}> */
    public array $saved = [];

    /** @var array<int, string> */
    public array $current = [];

    public ?string $fail = null;
    public bool $dsSupported = true;
    public ?string $dsFail = null;
    /** @var array<int,array{0:string,1:array<string,mixed>}> */
    public array $dsCalls = [];

    public function get(int $domainId): array
    {
        return $this->current === [] ? ['success' => false, 'nameservers' => []] : ['success' => true, 'nameservers' => $this->current];
    }

    public function save(int $domainId, array $nameservers): array
    {
        if ($this->fail !== null) {
            return ['success' => false, 'message' => $this->fail];
        }

        $this->saved[] = [$domainId, $nameservers];
        $this->current = $nameservers;
        return ['success' => true];
    }

    public function supportsDs(int $domainId): bool { return $this->dsSupported; }

    public function changeDs(int $domainId, array $ds, bool $add): array
    {
        if ($this->dsFail !== null) { return ['success'=>false,'message'=>$this->dsFail]; }
        $this->dsCalls[] = [$add?'add':'remove',$ds];
        return ['success'=>true];
    }
}

final class FakeOriginKeyGenerator implements OriginCertificateKeyGenerator
{
    public function generate(string $commonName): array
    {
        return ['success'=>true,'csr'=>"-----BEGIN CERTIFICATE REQUEST-----\nFAKE {$commonName}\n-----END CERTIFICATE REQUEST-----\n",'privateKey'=>"-----BEGIN PRIVATE KEY-----\nFAKE KEY\n-----END PRIVATE KEY-----\n"];
    }
}

final class FakeOriginInstaller implements OriginCertificateInstaller
{
    public bool $supported=true;
    public ?string $fail=null;
    /** @var array<int,array{service:int,domain:string,certificate:string,privateKey:string}> */
    public array $installed=[];
    public function supports(int $serviceId): bool { return $this->supported; }
    public function install(int $serviceId,string $domain,string $certificate,string $privateKey): array
    {
        if ($this->fail!==null) { return ['success'=>false,'message'=>$this->fail]; }
        $this->installed[]=['service'=>$serviceId,'domain'=>$domain,'certificate'=>$certificate,'privateKey'=>$privateKey];
        return ['success'=>true,'message'=>'Installed.'];
    }
}

/**
 * A small stateful Cloudflare: zones, DNS records, settings and IP access rules,
 * answering in the v4 envelope. Records every request.
 */
final class FakeCloudflare implements HttpClient
{
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>> */
    public array $zones = [];

    /** @var array<string, array<string, array<string, mixed>>> zone => id => record */
    public array $records = [];

    /** @var array<string, array<string, mixed>> */
    public array $settings = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $rules = [];

    /** @var array<int, array<string, mixed>> what a DNS scan finds */
    public array $scanRecords = [];
    /** @var array<string,array<string,mixed>> */ public array $dnssec=[];
    public bool $failDnssecGet=false;
    /** @var array<string,array<string,array<string,mixed>>> */ public array $rulesets=[];
    /** @var array<int,array<string,mixed>> */ public array $trafficGroups=[];
    /** @var array<string,array<string,mixed>> */ public array $certificates=[];

    private int $seq = 0;

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $path = (string) parse_url(substr($url, strlen(CloudflareApi::BASE)), PHP_URL_PATH);
        $data = $body !== null ? (array) json_decode($body, true) : [];
        $parts = array_values(array_filter(explode('/', $path), 'strlen'));

        if ($path === '/graphql' && $method === 'POST') {
            $vars=(array)($data['variables']??[]);
            $rows=array_values(array_filter($this->trafficGroups,static function(array $g) use($vars):bool {
                $d=(string)($g['dimensions']['date']??''); return $d>=(string)($vars['since']??'') && $d<=(string)($vars['until']??'');
            }));
            return ['status'=>200,'body'=>json_encode(['data'=>['viewer'=>['zones'=>[['httpRequests1dGroups'=>$rows]]]],'errors'=>null])];
        }
        if ($path === '/certificates' && $method === 'POST') {
            $id=md5('origin'.++$this->seq); $cert="-----BEGIN CERTIFICATE-----\nFAKE".$id."\n-----END CERTIFICATE-----\n";
            $this->certificates[$id]=['id'=>$id,'certificate'=>$cert,'csr'=>$data['csr']??'','hostnames'=>$data['hostnames']??[],'expires_on'=>'2041-01-01T00:00:00Z'];
            return self::ok($this->certificates[$id]);
        }
        if (preg_match('#^/certificates/([A-Za-z0-9]{1,64})$#',$path,$m) && $method==='DELETE') { unset($this->certificates[$m[1]]); return self::ok(['id'=>$m[1]]); }

        if ($path === '/user/tokens/verify') {
            return self::ok(['id' => 't1', 'status' => 'active']);
        }

        if ($path === '/accounts') {
            return self::ok([['id' => 'acc123', 'name' => 'Hosting Co']]);
        }

        if ($path === '/zones' && $method === 'POST') {
            foreach ($this->zones as $zone) {
                if ($zone['name'] === $data['name']) {
                    return self::error(400, 1061, $data['name'] . ' already exists');
                }
            }

            $id = md5('zone' . ++$this->seq);
            $this->zones[$id] = ['id' => $id, 'name' => $data['name'], 'status' => 'pending', 'paused' => false, 'plan' => ['name' => 'Free Website'],
                'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], 'original_name_servers' => ['ns1.oldhost.net', 'ns2.oldhost.net']];
            $this->records[$id] = [];
            $this->settings[$id] = ['ssl' => 'flexible', 'always_use_https' => 'off', 'security_level' => 'medium', 'development_mode' => 'off', 'browser_check' => 'on', 'cache_level' => 'aggressive', 'browser_cache_ttl' => 14400, 'min_tls_version' => '1.0', 'automatic_https_rewrites' => 'on'];
            $this->rules[$id] = [];
            $this->dnssec[$id] = ['status'=>'disabled'];
            $this->rulesets[$id] = [];
            $this->settings[$id] += ['early_hints'=>'off','http3'=>'on','0rtt'=>'off','rocket_loader'=>'off','always_online'=>'on','ipv6'=>'on','websockets'=>'on','opportunistic_encryption'=>'on','tls_1_3'=>'on','email_obfuscation'=>'on','hotlink_protection'=>'off'];

            return self::ok($this->zones[$id]);
        }

        $zoneId = $parts[1] ?? '';

        if (($parts[0] ?? '') !== 'zones' || !isset($this->zones[$zoneId])) {
            return self::error(404, 1001, 'Invalid zone identifier');
        }

        $rest = implode('/', array_slice($parts, 2));
        if ($rest==='rulesets' && $method==='POST') {
            $phase=(string)($data['phase']??''); $rows=[];
            foreach((array)($data['rules']??[]) as $rule){$rule['id']=md5('rule'.++$this->seq);$rows[]=$rule;}
            $set=['id'=>md5($zoneId.$phase),'kind'=>'zone','phase'=>$phase,'name'=>(string)($data['name']??''),'rules'=>$rows];
            $this->rulesets[$zoneId][$phase]=$set; return self::ok($set);
        }
        if ($rest==='dnssec' && $method==='GET') { return $this->failDnssecGet ? self::error(403,10000,'DNSSEC read permission denied') : self::ok($this->dnssec[$zoneId]??['status'=>'disabled']); }
        if ($rest==='dnssec' && $method==='PATCH') {
            if (($data['status']??'')==='active') { $this->dnssec[$zoneId]=['status'=>'active','key_tag'=>2371,'algorithm'=>13,'digest_type'=>2,'digest'=>str_repeat('A1B2C3D4',8),'ds'=>'2371 13 2 '.str_repeat('A1B2C3D4',8)]; }
            else { $this->dnssec[$zoneId]=['status'=>'disabled']; }
            return self::ok($this->dnssec[$zoneId]);
        }
        if (str_starts_with($rest,'rulesets/')) {
            $phase=(string)($parts[4]??'');
            if (($parts[3]??'')==='phases' && ($parts[5]??'')==='entrypoint') {
                if ($method==='GET') { return isset($this->rulesets[$zoneId][$phase])?self::ok($this->rulesets[$zoneId][$phase]):self::error(404,7003,'Ruleset does not exist.'); }
                if ($method==='PUT') {
                    $rows=[]; foreach((array)($data['rules']??[]) as $rule){$rule['id']=md5('rule'.++$this->seq);$rows[]=$rule;}
                    $this->rulesets[$zoneId][$phase]=['id'=>md5($zoneId.$phase),'phase'=>$phase,'rules'=>$rows]; return self::ok($this->rulesets[$zoneId][$phase]);
                }
            }
            if (count($parts)===5 && ($parts[4]??'')==='rules' && $method==='POST') {
                $setId=$parts[3]; foreach($this->rulesets[$zoneId] as &$set){ if($set['id']===$setId){$r=$data;$r['id']=md5('rule'.++$this->seq);$set['rules'][]=$r;$copy=$set;unset($set);return self::ok($copy);} } unset($set);
            }
            if (count($parts)===6 && ($parts[4]??'')==='rules' && $method==='DELETE') {
                $setId=$parts[3];$rid=$parts[5];foreach($this->rulesets[$zoneId] as &$set){if($set['id']===$setId){$set['rules']=array_values(array_filter($set['rules'],static fn($r)=>$r['id']!==$rid));unset($set);return self::ok(['id'=>$rid]);}}unset($set);
            }
        }

        switch (true) {
            case $rest === '' && $method === 'GET':
                return self::ok($this->zones[$zoneId]);
            case $rest === '' && $method === 'PATCH':
                $this->zones[$zoneId]['paused'] = (bool) $data['paused'];

                return self::ok($this->zones[$zoneId]);
            case $rest === '' && $method === 'DELETE':
                unset($this->zones[$zoneId]);

                return self::ok(['id' => $zoneId]);
            case $rest === 'activation_check':
                return self::ok(['id' => $zoneId]);
            case $rest === 'dns_records/scan':
                foreach ($this->scanRecords as $record) {
                    $this->addRecord($zoneId, $record);
                }

                return self::ok(['recs_added' => count($this->scanRecords), 'total_records_parsed' => count($this->scanRecords)]);
            case $rest === 'dns_records/export':
                $lines = [];
                foreach ($this->records[$zoneId] as $r) {
                    $lines[] = $r['name'] . ".\t1\tIN\t" . $r['type'] . "\t" . $r['content'];
                }

                return ['status' => 200, 'body' => ";; zone\n" . implode("\n", $lines)];
            case $rest === 'dns_records' && $method === 'GET':
                return ['status' => 200, 'body' => json_encode(['success' => true, 'errors' => [], 'result' => array_values($this->records[$zoneId]), 'result_info' => ['page' => 1, 'total_pages' => 1]])];
            case $rest === 'dns_records' && $method === 'POST':
                return self::ok($this->addRecord($zoneId, $data));
            case str_starts_with($rest, 'dns_records/'):
                $rid = $parts[3];
                if (!isset($this->records[$zoneId][$rid])) {
                    return self::error(404, 81044, 'Record does not exist.');
                }
                if ($method === 'DELETE') {
                    unset($this->records[$zoneId][$rid]);

                    return self::ok(['id' => $rid]);
                }
                $this->records[$zoneId][$rid] = array_merge($this->records[$zoneId][$rid], $data);

                return self::ok($this->records[$zoneId][$rid]);
            case $rest === 'settings':
                $out = [];
                foreach ($this->settings[$zoneId] as $k => $v) {
                    $out[] = ['id' => $k, 'value' => $v, 'editable' => true];
                }

                return self::ok($out);
            case str_starts_with($rest, 'settings/'):
                $this->settings[$zoneId][$parts[3]] = $data['value'];

                return self::ok(['id' => $parts[3], 'value' => $data['value']]);
            case $rest === 'purge_cache':
                return self::ok(['id' => $zoneId]);
            case $rest === 'firewall/access_rules/rules' && $method === 'GET':
                return self::ok($this->rules[$zoneId]);
            case $rest === 'firewall/access_rules/rules' && $method === 'POST':
                $rule = ['id' => md5('rule' . ++$this->seq), 'mode' => $data['mode'], 'configuration' => $data['configuration'], 'notes' => $data['notes']];
                $this->rules[$zoneId][] = $rule;

                return self::ok($rule);
            case str_starts_with($rest, 'firewall/access_rules/rules/') && $method === 'DELETE':
                $this->rules[$zoneId] = array_values(array_filter($this->rules[$zoneId], static fn ($r) => $r['id'] !== $parts[5]));

                return self::ok(['id' => $parts[5]]);
        }

        return self::error(400, 7000, 'No route for ' . $method . ' ' . $path);
    }

    /** @return array<string, mixed>|null the first request matching method and path fragment */
    public function find(string $method, string $pathContains): ?array
    {
        foreach ($this->requests as $request) {
            if ($request['method'] === $method && str_contains($request['url'], $pathContains)) {
                return $request;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function addRecord(string $zoneId, array $record): array
    {
        $id = md5('rec' . ++$this->seq);
        $type = (string) $record['type'];
        $record += ['ttl' => 1, 'proxied' => false];
        $record['id'] = $id;
        $record['proxiable'] = in_array($type, ['A', 'AAAA', 'CNAME'], true);
        $this->records[$zoneId][$id] = $record;

        return $record;
    }

    private static function ok(mixed $result): array
    {
        return ['status' => 200, 'body' => json_encode(['success' => true, 'errors' => [], 'messages' => [], 'result' => $result])];
    }

    private static function error(int $status, int $code, string $message): array
    {
        return ['status' => $status, 'body' => json_encode(['success' => false, 'errors' => [['code' => $code, 'message' => $message]], 'result' => null])];
    }
}
