<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Cloudflare\CloudflareAddon;
use CodeVault\Cloudflare\CloudflareApi;
use CodeVault\Cloudflare\CloudflareApiException;
use CodeVault\Cloudflare\CloudflareCronJob;
use CodeVault\Cloudflare\CloudflareFeatures;
use CodeVault\Cloudflare\CloudflareEmailRouting;
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

    public function testZonePageIsFilteredToTheSelectedAccountAndDomain(): void
    {
        $id = str_repeat('a', 32);
        $otherId = str_repeat('b', 32);
        $this->cf->zones[$id] = ['id' => $id, 'name' => 'example.com', 'account' => ['id' => 'acc123'], 'plan' => ['id' => 'free', 'name' => 'Free Website']];
        $this->cf->zones[$otherId] = ['id' => $otherId, 'name' => 'example.com', 'account' => ['id' => 'other-account'], 'plan' => ['id' => 'free', 'name' => 'Free Website']];

        $result = $this->service->apiClient()->zonePage('acc123', 'example.com', 1);

        $this->assertCount(1, $result['zones']);
        $this->assertSame($id, $result['zones'][0]['id']);
        $this->assertSame(1, $result['total_pages']);
        $query = [];
        parse_str((string) parse_url($this->cf->requests[0]['url'], PHP_URL_QUERY), $query);
        $this->assertSame('acc123', (string) ($query['account.id'] ?? $query['account_id'] ?? ''));
        $this->assertSame('example.com', (string) ($query['name'] ?? ''));
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

    public function testExplicitImportLinksFreeZoneAndReadsDnsWithoutChangingCloudflareOrRegistrar(): void
    {
        $this->chooseCloudflareAtCheckout(500, 20);
        $cfZoneId = str_repeat('c', 32);
        $this->cf->zones[$cfZoneId] = [
            'id' => $cfZoneId,
            'name' => 'example.com',
            'account' => ['id' => 'acc123', 'name' => 'Hosting Co'],
            'status' => 'active',
            'paused' => true,
            'plan' => ['id' => 'free', 'name' => 'Free Website'],
            'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
            'original_name_servers' => ['ns1.oldhost.net', 'ns2.oldhost.net'],
        ];
        $this->cf->records[$cfZoneId] = [
            'dnsrecord01' => ['id' => 'dnsrecord01', 'type' => 'A', 'name' => 'example.com', 'content' => '203.0.113.10', 'ttl' => 1, 'proxied' => true],
            'dnsrecord02' => ['id' => 'dnsrecord02', 'type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com', 'ttl' => 3600, 'priority' => 10],
        ];
        $this->cf->dnssec[$cfZoneId] = [
            'status' => 'active', 'key_tag' => 2371, 'algorithm' => 13, 'digest_type' => 2,
            'digest' => str_repeat('A1B2C3D4', 8), 'ds' => '2371 13 2 ' . str_repeat('A1B2C3D4', 8),
        ];

        $candidates = $this->service->importCandidates('example.com');
        $this->assertCount(1, $candidates);
        $this->assertSame(100, (int) $candidates[0]['service_id']);

        $result = $this->service->importExistingZone($cfZoneId, 100, ['type' => 'admin', 'id' => 1]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertStringContainsString('read 2 DNS record(s)', $result['message']);
        $zone = $this->zones->liveForService(100);
        $this->assertSame($cfZoneId, $zone['cf_zone_id']);
        $this->assertSame('example.com', $zone['name']);
        $this->assertSame('active', $zone['status']);
        $this->assertSame(1, (int) $zone['paused']);
        $this->assertSame(0, (int) $zone['paused_by_us']);
        $this->assertSame(0, (int) $zone['ns_switched_by_us']);
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $zone['name_servers']);
        $this->assertSame(['ns1.oldhost.net', 'ns2.oldhost.net'], $zone['original_name_servers']);
        $this->assertSame('active', $zone['dnssec_status']);
        $this->assertSame(0, (int) $zone['dnssec_ds_by_us'], 'imported DS data is never marked as WHMP-managed');
        $this->assertNotNull($zone['last_synced_at']);
        $this->assertSame([], $this->ns->saved, 'import never calls the registrar');
        $this->assertContains('imported', array_column($this->zones->activity((int) $zone['id']), 'action'));
        foreach ($this->cf->requests as $request) {
            $this->assertSame('GET', $request['method'], 'import is read-only against Cloudflare');
        }
    }

    public function testImportedResellerCustomerRetainsUniqueStoreOwnership(): void
    {
        $this->db->exec("INSERT INTO services (id, client_id, order_id, product_id, dedicated_ip, product_name, domain, status) VALUES (102, 9, 502, 20, '203.0.113.30', 'Starter', 'storeclient.io', 'active')");
        $this->chooseCloudflareAtCheckout(502, 20);
        $cfZoneId = str_repeat('f', 32);
        $this->cf->zones[$cfZoneId] = [
            'id' => $cfZoneId, 'name' => 'storeclient.io', 'account' => ['id' => 'acc123'], 'status' => 'active',
            'plan' => ['id' => 'free', 'name' => 'Free Website'], 'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
        ];
        $this->cf->records[$cfZoneId] = [];

        $result = $this->service->importExistingZone($cfZoneId, 102, ['type' => 'admin', 'id' => 1]);

        $this->assertTrue($result['ok'], $result['message']);
        $zone = $this->zones->liveForService(102);
        $this->assertSame(9, (int) $zone['client_id']);
        $this->assertSame(3, (int) $zone['reseller_id']);
        $this->assertSame('Acme Hosting', $this->zones->search('live', 'storeclient')[0]['store_name']);
    }

    public function testImportBlocksAmbiguousServicesForTheSameDomain(): void
    {
        $this->db->exec("INSERT INTO services (id, client_id, order_id, product_id, dedicated_ip, product_name, domain, status) VALUES (101, 8, 501, 20, '203.0.113.20', 'Starter', 'example.com', 'active')");
        $this->chooseCloudflareAtCheckout(500, 20);
        $this->chooseCloudflareAtCheckout(501, 20);
        $cfZoneId = str_repeat('9', 32);

        $this->assertCount(2, $this->service->importCandidates('example.com'));
        $result = $this->service->importExistingZone($cfZoneId, 100, ['type' => 'admin', 'id' => 1]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Resolve the duplicate service assignment', $result['message']);
        $this->assertSame([], $this->cf->requests);
        $this->assertNull($this->zones->liveForService(100));
    }

    public function testImportRequiresRecordedCheckoutOptInAndFreePlan(): void
    {
        $cfZoneId = str_repeat('d', 32);
        $this->cf->zones[$cfZoneId] = [
            'id' => $cfZoneId, 'name' => 'example.com', 'account' => ['id' => 'acc123'], 'status' => 'active',
            'plan' => ['id' => 'free', 'name' => 'Free Website'], 'name_servers' => [],
        ];

        $notOptedIn = $this->service->importExistingZone($cfZoneId, 100, ['type' => 'admin', 'id' => 1]);
        $this->assertFalse($notOptedIn['ok']);
        $this->assertStringContainsString('recorded Free Cloudflare opt-in', $notOptedIn['message']);
        $this->assertSame([], $this->cf->requests, 'a service without an opt-in is rejected before remote lookup');

        $this->chooseCloudflareAtCheckout(500, 20);
        $this->cf->zones[$cfZoneId]['plan'] = ['id' => 'pro', 'name' => 'Pro Website'];
        $paid = $this->service->importExistingZone($cfZoneId, 100, ['type' => 'admin', 'id' => 1]);
        $this->assertFalse($paid['ok']);
        $this->assertStringContainsString('Free-plan', $paid['message']);
        $this->assertNull($this->zones->liveForService(100));
        foreach ($this->cf->requests as $request) {
            $this->assertSame('GET', $request['method'], 'a rejected paid-plan import is also read-only');
        }
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

    public function testAlreadyPointedNameserversAreNotClaimedAsChangedByWhmp(): void
    {
        $zone = $this->enabledZone();
        $this->registerDomain(7);
        $this->ns->current = $zone['name_servers'];

        $result = $this->service->switchNameservers((int) $zone['id'], ['type' => 'client', 'id' => 7]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertStringContainsString('No registrar change was made', $result['message']);
        $this->assertSame([], $this->ns->saved);
        $fresh = $this->zones->find((int) $zone['id']);
        $this->assertSame(0, (int) $fresh['ns_switched_by_us']);
        $this->assertSame(['ns1.oldhost.net', 'ns2.oldhost.net'], $fresh['original_name_servers']);
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
            'manageable' => true, 'emailManageable' => true, 'records' => [], 'settings' => [], 'rules' => [], 'activity' => [], 'loadError' => null, 'editId' => ''];

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

        $this->activateZone($zone);
        $activeZone = $this->zones->find((int) $zone['id']);
        $emailData = $this->emailRouting()->overview($activeZone, $this->emailActor($activeZone));
        $emailHtml = $view->render('cloudflare.client', ['zone' => $activeZone, 'tab' => 'email', 'emailRouting' => $emailData] + $base);
        $this->assertStringContainsString('Review email DNS before enabling', $emailHtml);
        $this->assertStringContainsString('route1.mx.cloudflare.net', $emailHtml);
        $this->assertStringContainsString('I confirm this domain has no existing mail provider', $emailHtml);
        $this->assertStringNotContainsString('Hosting Co', $emailHtml);

        $html = $view->render('cloudflare.client', ['zone' => null, 'tab' => 'overview', 'state' => ['show' => true, 'canEnable' => true, 'zone' => null, 'reason' => '']] + $base);
        $this->assertStringContainsString('Enable free Cloudflare', $html);

        foreach (['cloudflare.client'] as $tpl) {
            $html = $view->render($tpl, ['zone' => $zone, 'tab' => 'overview'] + $base);
            $this->assertStringNotContainsString('Hosting Co', $html, 'the platform\'s Cloudflare account is never shown to clients');
        }

        $html = $view->render('cloudflare.admin-index', ['counts' => $this->zones->counts(), 'rows' => $this->zones->search('live', ''), 'status' => 'live', 'q' => '', 'pageNo' => 1, 'connected' => true, 'accountName' => 'Hosting Co', 'productCount' => 1]);
        $this->assertStringContainsString('example.com', $html);
        $this->assertStringContainsString('/admin/cloudflare/zones/' . $zone['id'], $html);
        $importId = str_repeat('e', 32);
        $importHtml = $view->render('cloudflare.admin-import', [
            'connected' => true,
            'accountName' => 'Hosting Co',
            'remoteZones' => [[
                'zone' => ['id' => $importId, 'name' => 'example.net', 'status' => 'active', 'plan' => ['id' => 'free', 'name' => 'Free Website'], 'name_servers' => ['ada.ns.cloudflare.com']],
                'linked' => null,
                'name_owner' => null,
                'free_plan' => true,
                'candidates' => [[
                    'service_id' => 101, 'client_id' => 8, 'product_name' => 'Starter', 'first_name' => 'Bob',
                    'last_name' => 'B', 'client_email' => 'bob@example.com', 'client_reseller_id' => null,
                ]],
            ]],
            'q' => '', 'pageNo' => 1, 'totalPages' => 1, 'totalCount' => 1, 'loadError' => null,
            'notice' => null, 'error' => null,
        ]);
        $this->assertStringContainsString('Import and link', $importHtml);
        $this->assertStringContainsString('No nameserver switch happens automatically', $importHtml);
        $this->assertStringContainsString('name="cf_zone_id"', $importHtml);

        $html = $view->render('cloudflare.admin-zone', ['zone' => $this->zones->detailed((int) $zone['id']), 'activity' => $this->zones->activity((int) $zone['id']), 'graceDays' => 7]);
        $this->assertStringContainsString('Schedule removal (7 days)', $html);
        $this->assertStringContainsString('/admin/clients/7', $html);

        $option = new CloudflareProductOption($this->db, $this->settings);
        $html = $view->render('cloudflare.admin-settings', ['settings' => $this->settings, 'hasToken' => true, 'accounts' => [], 'products' => $option->products(), 'attached' => $option->attachedProductIds()]);
        $this->assertStringContainsString('Verify &amp; save', $html);
        $this->assertStringContainsString('Account Filter Lists', $html, 'the extra Cache Rules permission is documented');
        $this->assertStringContainsString('Zone · Zone · Read', $html, 'zone discovery read scope is documented');
        $this->assertStringContainsString('Zone · DNS · Read', $html, 'import DNS read scope is documented');
        $this->assertStringContainsString('Zone · Zone Settings · Read', $html, 'Email Routing preflight requires Zone Settings Read');
        $this->assertStringContainsString('Zone · Zone Settings · Write', $html, 'Email Routing activation requires Zone Settings Write');
        $this->assertStringContainsString('Zone · Email Routing Rules · Read', $html, 'routing rules are a zone-level scope');
        $this->assertStringContainsString('Zone · Email Routing Rules · Write', $html);
        $this->assertStringContainsString('Account · Email Routing Addresses · Read', $html, 'destination addresses are account-level');
        $this->assertStringContainsString('Account · Email Routing Addresses · Write', $html);
        $this->assertStringContainsString('account-level destinations shared across zones', $html);
        $this->assertStringContainsString('these are zone-level forwarding rules', $html);
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

    public function testEmailRoutingPreflightFailsClosedForExistingMxSpfAndDkim(): void
    {
        $zone = $this->activeEmailRoutingZone();
        $zoneId = (string) $zone['cf_zone_id'];
        $routing = $this->emailRouting();
        $actor = $this->emailActor($zone);

        $this->cf->records[$zoneId]['external-mx'] = [
            'id' => 'external-mx', 'type' => 'MX', 'name' => 'example.com', 'content' => 'mx.mail-provider.test', 'priority' => 10,
        ];
        $mxState = $routing->overview($zone, $this->emailActor($zone));
        $this->assertTrue($mxState['ok'], $mxState['message']);
        $this->assertCount(1, $mxState['mx_conflicts']);
        $this->assertFalse($mxState['can_enable']);
        $this->assertFalse($routing->enable($zone, true, $actor)['ok']);

        unset($this->cf->records[$zoneId]['external-mx']);
        $this->cf->records[$zoneId]['existing-spf'] = [
            'id' => 'existing-spf', 'type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 include:_spf.mail-provider.test ~all',
        ];
        $spfState = $routing->overview($zone, $this->emailActor($zone));
        $this->assertCount(1, $spfState['spf_records']);
        $this->assertFalse($spfState['can_enable']);
        $spfAttempt = $routing->enable($zone, true, $actor);
        $this->assertFalse($spfAttempt['ok']);
        $this->assertStringContainsString('SPF record already exists', $spfAttempt['message']);

        unset($this->cf->records[$zoneId]['existing-spf']);
        $this->cf->records[$zoneId]['existing-dkim'] = [
            'id' => 'existing-dkim', 'type' => 'TXT', 'name' => 'selector._domainkey.example.com', 'content' => 'v=DKIM1; k=rsa; p=EXISTING_KEY',
        ];
        $dkimState = $routing->overview($zone, $this->emailActor($zone));
        $this->assertCount(1, $dkimState['dkim_records']);
        $this->assertFalse($dkimState['can_enable']);
        $dkimAttempt = $routing->enable($zone, true, $actor);
        $this->assertFalse($dkimAttempt['ok']);
        $this->assertStringContainsString('DKIM records were found', $dkimAttempt['message']);

        $this->assertNull($this->cf->find('POST', '/email/routing/dns'), 'The preflight never calls Cloudflare when existing mail records are present');
        $this->assertSame([], $this->ns->saved, 'Email Routing never touches nameservers');
        $this->assertArrayHasKey('existing-dkim', $this->cf->records[$zoneId], 'All external records remain untouched');
    }

    public function testEmailRoutingActivationRequiresCompleteMxSpfAndDkimChecklist(): void
    {
        $zone = $this->activeEmailRoutingZone();
        $zoneId = (string) $zone['cf_zone_id'];
        $routing = $this->emailRouting();
        $actor = $this->emailActor($zone);
        $complete = $this->cf->requiredEmailRoutingDns[$zoneId];
        $nonApexSpf = array_map(static function (array $record): array {
            if (strtoupper((string) ($record['type'] ?? '')) === 'TXT'
                && str_starts_with(strtolower((string) ($record['content'] ?? '')), 'v=spf1')) {
                $record['name'] = 'mail.example.com';
            }

            return $record;
        }, $complete);
        $wrongSpfInclude = array_map(static function (array $record): array {
            if (strtoupper((string) ($record['type'] ?? '')) === 'TXT'
                && str_starts_with(strtolower((string) ($record['content'] ?? '')), 'v=spf1')) {
                $record['content'] = 'v=spf1 include:_spf.other.test ~all';
            }

            return $record;
        }, $complete);
        $emptyDkimKey = array_map(static function (array $record): array {
            if (strtoupper((string) ($record['type'] ?? '')) === 'TXT'
                && str_contains(strtolower((string) ($record['name'] ?? '')), '._domainkey')) {
                $record['content'] = 'v=DKIM1; h=sha256; k=rsa; p=';
            }

            return $record;
        }, $complete);
        $incompleteCases = [
            'MX' => array_values(array_filter($complete, static fn (array $record): bool => !(
                strtoupper((string) ($record['type'] ?? '')) === 'MX'
                && (string) ($record['content'] ?? '') === 'route3.mx.cloudflare.net'
            ))),
            'apex SPF' => $nonApexSpf,
            'Cloudflare SPF include' => $wrongSpfInclude,
            'DKIM' => array_values(array_filter($complete, static fn (array $record): bool => !(
                strtoupper((string) ($record['type'] ?? '')) === 'TXT'
                && str_contains(strtolower((string) ($record['name'] ?? '')), '._domainkey')
            ))),
            'DKIM public key' => $emptyDkimKey,
        ];

        foreach ($incompleteCases as $missing => $checklist) {
            $this->cf->requiredEmailRoutingDns[$zoneId] = $checklist;
            $view = $routing->overview($zone, $actor);
            $this->assertTrue($view['ok'], $view['message']);
            $this->assertFalse($view['required_dns_complete'], 'A checklist missing ' . $missing . ' must fail closed');
            $this->assertFalse($view['can_enable']);

            $enabled = $routing->enable($zone, true, $actor);
            $this->assertFalse($enabled['ok']);
            $this->assertStringContainsString('complete Email Routing MX, SPF and DKIM DNS checklist', $enabled['message']);
            $this->assertNull($this->cf->find('POST', '/email/routing/dns'), 'Incomplete ' . $missing . ' checklist must not call the activation endpoint');
        }

        $this->cf->requiredEmailRoutingDns[$zoneId] = $complete;
        $view = $routing->overview($zone, $actor);
        $this->assertTrue($view['required_dns_complete']);
        $this->assertTrue($view['can_enable']);
    }

    public function testEmailRoutingEnableIsExplicitAndUsesCloudflareManagedDnsEndpoint(): void
    {
        $zone = $this->activeEmailRoutingZone();
        $zoneId = (string) $zone['cf_zone_id'];
        $this->cf->records[$zoneId]['external-a'] = [
            'id' => 'external-a', 'type' => 'A', 'name' => 'mail.example.com', 'content' => '203.0.113.55', 'ttl' => 1, 'proxied' => false,
        ];
        $routing = $this->emailRouting();
        $before = $routing->overview($zone, $this->emailActor($zone));
        $this->assertTrue($before['ok'], $before['message']);
        $this->assertTrue($before['required_dns_complete']);
        $this->assertTrue($before['can_enable']);
        $this->assertSame([], $before['mx_records']);
        $this->assertSame([], $before['spf_records']);
        $this->assertSame([], $before['dkim_records']);
        $this->assertNull($this->cf->find('POST', '/email/routing/dns'), 'Opening the tab is read-only');

        $notConfirmed = $routing->enable($zone, false, $this->emailActor($zone));
        $this->assertFalse($notConfirmed['ok']);
        $this->assertNull($this->cf->find('POST', '/email/routing/dns'), 'Activation requires an explicit confirmation');

        $enabled = $routing->enable($zone, true, $this->emailActor($zone));
        $this->assertTrue($enabled['ok'], $enabled['message']);
        $enableRequest = $this->cf->find('POST', '/email/routing/dns');
        $this->assertNotNull($enableRequest);
        $this->assertStringContainsString('/zones/' . $zoneId . '/email/routing/dns', $enableRequest['url']);
        $this->assertSame('example.com', json_decode((string) $enableRequest['body'], true)['name']);
        $this->assertArrayHasKey('external-a', $this->cf->records[$zoneId]);

        $after = $routing->overview($zone, $this->emailActor($zone));
        $this->assertTrue($after['enabled']);
        $this->assertTrue($after['ready']);
        $this->assertTrue($after['can_manage']);
        $this->assertCount(3, $after['mx_records']);
        $this->assertCount(1, $after['spf_records']);
        $this->assertCount(1, $after['dkim_records']);

        $deleteCount = count(array_filter($this->cf->requests, static fn (array $r): bool => $r['method'] === 'DELETE' && str_contains($r['url'], '/email/routing/dns')));
        $notConfirmedDisable = $routing->disable($zone, false, $this->emailActor($zone));
        $this->assertFalse($notConfirmedDisable['ok']);
        $this->assertSame($deleteCount, count(array_filter($this->cf->requests, static fn (array $r): bool => $r['method'] === 'DELETE' && str_contains($r['url'], '/email/routing/dns'))));

        $disabled = $routing->disable($zone, true, $this->emailActor($zone));
        $this->assertTrue($disabled['ok'], $disabled['message']);
        $this->assertFalse($this->cf->emailRoutingSettings[$zoneId]['enabled']);
        $this->assertArrayHasKey('external-a', $this->cf->records[$zoneId], 'Disabling removes only Email Routing-managed DNS');
        $this->assertSame([], $this->ns->saved, 'Neither activation nor disable switches nameservers');
    }

    public function testEmailRoutingDestinationsAndRulesAreStrictlyClientAndResellerScoped(): void
    {
        $zone = $this->activeEmailRoutingZone();
        $this->enableTestEmailRouting($zone);
        $routing = $this->emailRouting();
        $owner = $this->emailActor($zone);
        $wrongReseller = $owner;
        $wrongReseller['reseller_id'] = ($owner['reseller_id'] ?? 0) + 1000;
        $deniedView = $routing->overview($zone, $wrongReseller);
        $this->assertFalse($deniedView['ok'], 'The same client ID with a different reseller ID is not the owner');
        $this->assertSame([], $deniedView['destinations']);
        $this->assertFalse($routing->addDestination($zone, 'intruder@example.net', $wrongReseller)['ok']);
        $this->assertCount(0, $this->cf->emailDestinations, 'A mismatched reseller cannot create a shared-account destination');

        $added = $routing->addDestination($zone, 'Ada@Example.net', $owner);
        $this->assertTrue($added['ok'], $added['message']);
        $destination = $this->db->selectOne('SELECT * FROM cloudflare_email_destinations WHERE email = ?', ['ada@example.net']);
        $destinationId = (int) $destination['id'];
        $remoteDestinationId = (string) $destination['cf_destination_id'];

        $pendingView = $routing->overview($zone, $this->emailActor($zone));
        $this->assertCount(1, $pendingView['destinations']);
        $this->assertSame('pending', $pendingView['destinations'][0]['status']);
        $pendingRoute = $routing->addRoute($zone, 'sales', $destinationId, $owner);
        $this->assertFalse($pendingRoute['ok'], 'An unverified destination cannot receive a route');
        $this->assertSame([], $this->cf->emailRoutingRules[(string) $zone['cf_zone_id']]);

        $this->cf->emailDestinations[$remoteDestinationId]['verified'] = '2026-10-08T12:00:00Z';
        $verifiedView = $routing->overview($zone, $this->emailActor($zone));
        $this->assertSame('verified', $verifiedView['destinations'][0]['status']);
        $routeAdded = $routing->addRoute($zone, 'Sales', $destinationId, $owner);
        $this->assertTrue($routeAdded['ok'], $routeAdded['message']);
        $route = $this->db->selectOne('SELECT * FROM cloudflare_email_routes WHERE zone_id = ? AND local_part = ?', [(int) $zone['id'], 'sales']);
        $routeId = (int) $route['id'];
        $remoteRuleId = (string) $route['cf_rule_id'];
        $remoteRule = $this->cf->emailRoutingRules[(string) $zone['cf_zone_id']][$remoteRuleId];
        $this->assertSame('sales@example.com', $remoteRule['matchers'][0]['value']);
        $this->assertSame(['ada@example.net'], $remoteRule['actions'][0]['value']);
        $this->assertFalse($routing->addRoute($zone, 'sales@attacker.test', $destinationId, $owner)['ok'], 'Aliases cannot choose a different domain');
        $this->assertTrue($routing->setRouteEnabled($zone, $routeId, false, $owner)['ok']);
        $this->assertFalse($this->cf->emailRoutingRules[(string) $zone['cf_zone_id']][$remoteRuleId]['enabled']);

        $secondRule = $routing->addRoute($zone, 'help', $destinationId, $owner);
        $this->assertTrue($secondRule['ok'], $secondRule['message']);
        $helpRow = $this->db->selectOne('SELECT * FROM cloudflare_email_routes WHERE zone_id = ? AND local_part = ?', [(int) $zone['id'], 'help']);
        $this->assertTrue($routing->deleteRoute($zone, (int) $helpRow['id'], $owner)['ok']);
        $this->assertArrayNotHasKey((string) $helpRow['cf_rule_id'], $this->cf->emailRoutingRules[(string) $zone['cf_zone_id']]);

        // An out-of-band edit makes the saved mapping read-only; a toggle cannot
        // redirect or overwrite a rule whose remote target no longer matches.
        $this->cf->emailRoutingRules[(string) $zone['cf_zone_id']][$remoteRuleId]['actions'][0]['value'] = ['other@private.test'];
        $putCount = count(array_filter($this->cf->requests, static fn (array $r): bool => $r['method'] === 'PUT' && str_contains($r['url'], '/email/routing/rules/')));
        $tampered = $routing->setRouteEnabled($zone, $routeId, true, $owner);
        $this->assertFalse($tampered['ok']);
        $this->assertSame($putCount, count(array_filter($this->cf->requests, static fn (array $r): bool => $r['method'] === 'PUT' && str_contains($r['url'], '/email/routing/rules/'))));

        // A reseller customer owns a different service and cannot see, claim, or
        // route through this account-wide Cloudflare destination.
        $this->db->exec("INSERT INTO services (id, client_id, order_id, product_id, server_id, dedicated_ip, product_name, domain, status) VALUES (102, 9, 502, 20, NULL, '203.0.113.20', 'Starter', 'shop.example.net', 'active')");
        $otherEnable = $this->service->enable(102, ['type' => 'client', 'id' => 9]);
        $this->assertTrue($otherEnable['ok'], $otherEnable['message']);
        $otherZone = $this->zones->liveForService(102);
        $this->activateZone($otherZone);
        $otherZone = $this->zones->find((int) $otherZone['id']);
        $otherActor = $this->emailActor($otherZone);
        $otherView = $routing->overview($otherZone, $otherActor);
        $this->assertSame([], $otherView['destinations']);
        $this->assertSame([], $otherView['routes']);
        $this->assertFalse($routing->overview($zone, $otherActor)['ok'], 'A reseller user cannot even read another tenant zone through this service');
        $this->assertFalse($routing->addRoute($otherZone, 'stolen', $destinationId, $otherActor)['ok']);
        $foreignClaim = $routing->addDestination($otherZone, 'ada@example.net', $this->emailActor($otherZone));
        $this->assertFalse($foreignClaim['ok']);
        $this->assertStringNotContainsString('ada@example.net', $foreignClaim['message']);
        $this->assertCount(1, $this->cf->emailDestinations, 'The foreign tenant did not create or attach a Cloudflare destination');
    }

    public function testEmailRoutingCatchAllIsOffByDefaultAndRequiresExplicitOwnerScopedSetup(): void
    {
        $zone = $this->activeEmailRoutingZone();
        $this->enableTestEmailRouting($zone);
        $routing = $this->emailRouting();
        $actor = $this->emailActor($zone);
        $initial = $routing->overview($zone, $this->emailActor($zone));
        $this->assertFalse($initial['catch_all']['enabled']);
        $this->assertFalse($initial['catch_all']['external']);

        $added = $routing->addDestination($zone, 'owner@example.net', $actor);
        $this->assertTrue($added['ok'], $added['message']);
        $destination = $this->db->selectOne('SELECT * FROM cloudflare_email_destinations WHERE email = ?', ['owner@example.net']);
        $remoteId = (string) $destination['cf_destination_id'];
        $this->cf->emailDestinations[$remoteId]['verified'] = '2026-10-08T12:00:00Z';

        $enabled = $routing->setCatchAll($zone, true, (int) $destination['id'], $actor);
        $this->assertTrue($enabled['ok'], $enabled['message']);
        $catchRow = $this->db->selectOne('SELECT * FROM cloudflare_email_routes WHERE zone_id = ? AND local_part = ?', [(int) $zone['id'], '*']);
        $this->assertNotNull($catchRow);
        $catchView = $routing->overview($zone, $this->emailActor($zone))['catch_all'];
        $this->assertTrue($catchView['enabled']);
        $this->assertTrue($catchView['managed']);
        $this->assertSame('owner@example.net', $catchView['destination_email']);

        $putCount = count(array_filter($this->cf->requests, static fn (array $r): bool => $r['method'] === 'PUT' && str_contains($r['url'], '/email/routing/rules/catch_all')));
        $unconfirmed = $routing->setCatchAll($zone, false, null, $actor);
        $this->assertFalse($unconfirmed['ok']);
        $this->assertSame($putCount, count(array_filter($this->cf->requests, static fn (array $r): bool => $r['method'] === 'PUT' && str_contains($r['url'], '/email/routing/rules/catch_all'))));
        $disabled = $routing->setCatchAll($zone, false, null, $actor, true);
        $this->assertTrue($disabled['ok'], $disabled['message']);
        $this->assertFalse($this->cf->emailCatchAlls[(string) $zone['cf_zone_id']]['enabled']);

        // A subsequent out-of-band edit blocks further changes and does not
        // expose the new destination to this client's page.
        $this->cf->emailCatchAlls[(string) $zone['cf_zone_id']]['enabled'] = true;
        $this->cf->emailCatchAlls[(string) $zone['cf_zone_id']]['actions'] = [['type' => 'forward', 'value' => ['private@outside.test']]];
        $external = $routing->overview($zone, $this->emailActor($zone))['catch_all'];
        $this->assertTrue($external['changed']);
        $this->assertStringNotContainsString('private@outside.test', json_encode($external));
        $changed = $routing->setCatchAll($zone, false, null, $actor, true);
        $this->assertFalse($changed['ok']);
    }

    public function testEmailRoutingRejectsPaidZonesAndAddressValidationIsStrict(): void
    {
        $zone = $this->activeEmailRoutingZone();
        $this->cf->zones[(string) $zone['cf_zone_id']]['plan'] = ['id' => 'pro', 'name' => 'Pro'];
        $routing = $this->emailRouting();
        $view = $routing->overview($zone, $this->emailActor($zone));
        $this->assertFalse($view['ok']);
        $this->assertStringContainsString('Free-plan', $view['message']);
        $result = $routing->enable($zone, true, $this->emailActor($zone));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Free-plan', $result['message']);
        $this->assertNull($this->cf->find('POST', '/email/routing/dns'));

        $this->assertSame('valid@example.net', CloudflareEmailRouting::validateDestination(' Valid@Example.net '));
        $this->assertNull(CloudflareEmailRouting::validateDestination('not-an-email'));
        $this->assertSame('sales+tag@example.com', CloudflareEmailRouting::validateLocalPart('Sales+Tag', 'example.com'));
        $this->assertNull(CloudflareEmailRouting::validateLocalPart('sales@elsewhere.test', 'example.com'));
        $this->assertNull(CloudflareEmailRouting::validateLocalPart('-admin', 'example.com'));
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

    private function emailRouting(): CloudflareEmailRouting
    {
        return new CloudflareEmailRouting($this->service, $this->zones, $this->settings, $this->db);
    }

    /** @param array<string,mixed> $zone @return array{type:string,id:int,reseller_id:int|null} */
    private function emailActor(array $zone): array
    {
        return [
            'type' => 'client',
            'id' => (int) $zone['client_id'],
            'reseller_id' => ($zone['reseller_id'] ?? null) === null ? null : (int) $zone['reseller_id'],
        ];
    }

    /** @return array<string,mixed> active service-owned zone with no pre-existing mail DNS */
    private function activeEmailRoutingZone(): array
    {
        $zone = $this->enabledZone();
        $this->activateZone($zone);

        return $this->zones->find((int) $zone['id']);
    }

    /** @param array<string,mixed> $zone */
    private function enableTestEmailRouting(array $zone): array
    {
        $result = $this->emailRouting()->enable($zone, true, $this->emailActor($zone));
        $this->assertTrue($result['ok'], $result['message']);

        return $result;
    }

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

    private function chooseCloudflareAtCheckout(int $orderId, int $productId): void
    {
        $options = json_encode([(string) $this->settings->optionGroupId() => $this->settings->optionYesId()]);
        $this->db->statement(
            'INSERT INTO order_items (order_id, product_id, configurable_options) VALUES (?, ?, ?)',
            [$orderId, $productId, $options]
        );
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
            CREATE TABLE cloudflare_email_destinations (
                id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INT NOT NULL, reseller_id INT NULL,
                cf_destination_id TEXT NULL UNIQUE, email TEXT NOT NULL UNIQUE, verified_at TEXT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            );
            CREATE TABLE cloudflare_email_routes (
                id INTEGER PRIMARY KEY AUTOINCREMENT, zone_id INT NOT NULL, client_id INT NOT NULL, reseller_id INT NULL,
                cf_rule_id TEXT NOT NULL, local_part TEXT NOT NULL, destination_id INT NOT NULL, enabled INT NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(zone_id, cf_rule_id), UNIQUE(zone_id, local_part),
                FOREIGN KEY(destination_id) REFERENCES cloudflare_email_destinations(id) ON DELETE RESTRICT
            );
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
    /** @var array<string,array<string,mixed>> account-scoped Email Routing destination address ID => address */
    public array $emailDestinations = [];
    /** @var array<string,array<string,mixed>> zone => Email Routing settings */
    public array $emailRoutingSettings = [];
    /** @var array<string,array<int,array<string,mixed>>> zone => required Email Routing DNS records */
    public array $requiredEmailRoutingDns = [];
    /** @var array<string,array<string,array<string,mixed>>> zone => routing rule ID => rule */
    public array $emailRoutingRules = [];
    /** @var array<string,array<string,mixed>> zone => separate catch-all */
    public array $emailCatchAlls = [];
    /** @var array<string,array<int,string>> zone => fake-DNS IDs managed by Email Routing */
    public array $emailRoutingManagedDns = [];

    private int $seq = 0;

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $path = (string) parse_url(substr($url, strlen(CloudflareApi::BASE)), PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
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

        if ($path === '/zones' && $method === 'GET') {
            $rows = array_values(array_filter($this->zones, static function (array $zone) use ($query): bool {
                $accountId = (string) ($query['account.id'] ?? $query['account_id'] ?? '');
                $zoneAccountId = (string) ($zone['account']['id'] ?? 'acc123');
                $name = (string) ($query['name'] ?? '');

                return ($accountId === '' || $zoneAccountId === $accountId)
                    && ($name === '' || strcasecmp((string) ($zone['name'] ?? ''), $name) === 0);
            }));
            usort($rows, static fn (array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
            $perPage = max(1, (int) ($query['per_page'] ?? 50));
            $page = max(1, (int) ($query['page'] ?? 1));
            $total = count($rows);
            $paged = array_slice($rows, ($page - 1) * $perPage, $perPage);

            return ['status' => 200, 'body' => json_encode([
                'success' => true,
                'errors' => [],
                'result' => $paged,
                'result_info' => ['page' => $page, 'per_page' => $perPage, 'total_pages' => max(1, (int) ceil($total / $perPage)), 'total_count' => $total],
            ])];
        }

        if ($path === '/zones' && $method === 'POST') {
            foreach ($this->zones as $zone) {
                if ($zone['name'] === $data['name']) {
                    return self::error(400, 1061, $data['name'] . ' already exists');
                }
            }

            $id = md5('zone' . ++$this->seq);
            $this->zones[$id] = ['id' => $id, 'name' => $data['name'], 'account' => ['id' => 'acc123', 'name' => 'Hosting Co'], 'status' => 'pending', 'paused' => false, 'plan' => ['id' => 'free', 'name' => 'Free Website'],
                'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], 'original_name_servers' => ['ns1.oldhost.net', 'ns2.oldhost.net']];
            $this->records[$id] = [];
            $this->settings[$id] = ['ssl' => 'flexible', 'always_use_https' => 'off', 'security_level' => 'medium', 'development_mode' => 'off', 'browser_check' => 'on', 'cache_level' => 'aggressive', 'browser_cache_ttl' => 14400, 'min_tls_version' => '1.0', 'automatic_https_rewrites' => 'on'];
            $this->rules[$id] = [];
            $this->dnssec[$id] = ['status'=>'disabled'];
            $this->rulesets[$id] = [];
            $this->ensureEmailRoutingState($id);
            $this->settings[$id] += ['early_hints'=>'off','http3'=>'on','0rtt'=>'off','rocket_loader'=>'off','always_online'=>'on','ipv6'=>'on','websockets'=>'on','opportunistic_encryption'=>'on','tls_1_3'=>'on','email_obfuscation'=>'on','hotlink_protection'=>'off'];

            return self::ok($this->zones[$id]);
        }

        $accountAddressesPath = '/accounts/' . (string) ($parts[1] ?? '') . '/email/routing/addresses';
        if ((string) ($parts[0] ?? '') === 'accounts' && (string) ($parts[1] ?? '') === 'acc123'
            && $path === $accountAddressesPath) {
            if ($method === 'GET') {
                $rows = array_values($this->emailDestinations);
                $perPage = max(5, min(50, (int) ($query['per_page'] ?? 50)));
                $page = max(1, (int) ($query['page'] ?? 1));
                $total = count($rows);
                $paged = array_slice($rows, ($page - 1) * $perPage, $perPage);

                return ['status' => 200, 'body' => json_encode([
                    'success' => true, 'errors' => [], 'result' => $paged,
                    'result_info' => ['page' => $page, 'per_page' => $perPage, 'total_pages' => max(1, (int) ceil($total / $perPage)), 'total_count' => $total],
                ])];
            }

            if ($method === 'POST') {
                $email = strtolower(trim((string) ($data['email'] ?? '')));
                foreach ($this->emailDestinations as $existing) {
                    if (strcasecmp((string) ($existing['email'] ?? ''), $email) === 0) {
                        return self::error(400, 81058, 'Destination already exists.');
                    }
                }

                $id = md5('email-destination' . ++$this->seq);
                $this->emailDestinations[$id] = ['id' => $id, 'email' => $email, 'verified' => null, 'created' => gmdate('c'), 'modified' => gmdate('c')];

                return self::ok($this->emailDestinations[$id]);
            }
        }
        if (preg_match('#^/accounts/([A-Za-z0-9]{1,64})/email/routing/addresses/([A-Za-z0-9]{1,64})$#', $path, $addressMatch)
            && $addressMatch[1] === 'acc123') {
            $addressId = $addressMatch[2];
            if ($method === 'GET' && isset($this->emailDestinations[$addressId])) {
                return self::ok($this->emailDestinations[$addressId]);
            }
            if ($method === 'DELETE' && isset($this->emailDestinations[$addressId])) {
                unset($this->emailDestinations[$addressId]);

                return self::ok(['id' => $addressId]);
            }
            return self::error(404, 1001, 'Destination address does not exist.');
        }

        $zoneId = $parts[1] ?? '';

        if (($parts[0] ?? '') !== 'zones' || !isset($this->zones[$zoneId])) {
            return self::error(404, 1001, 'Invalid zone identifier');
        }

        $rest = implode('/', array_slice($parts, 2));
        if (str_starts_with($rest, 'email/routing')) {
            $this->ensureEmailRoutingState($zoneId);

            if ($rest === 'email/routing' && $method === 'GET') {
                return self::ok($this->emailRoutingSettings[$zoneId]);
            }
            if ($rest === 'email/routing/dns' && $method === 'GET') {
                return self::ok($this->requiredEmailRoutingDns[$zoneId]);
            }
            if ($rest === 'email/routing/dns' && $method === 'POST') {
                $required = $this->requiredEmailRoutingDns[$zoneId];
                foreach ($required as $requiredRecord) {
                    foreach ($this->records[$zoneId] ?? [] as $existingRecord) {
                        if (strcasecmp((string) ($existingRecord['name'] ?? ''), (string) $requiredRecord['name']) === 0
                            && strtoupper((string) ($existingRecord['type'] ?? '')) === strtoupper((string) $requiredRecord['type'])) {
                            return self::error(400, 81058, 'An Email Routing DNS record conflicts with an existing record.');
                        }
                    }
                }
                foreach ($required as $record) {
                    $this->emailRoutingManagedDns[$zoneId][] = (string) $this->addRecord($zoneId, $record)['id'];
                }
                $this->emailRoutingSettings[$zoneId]['enabled'] = true;
                $this->emailRoutingSettings[$zoneId]['status'] = 'ready';

                return self::ok($this->emailRoutingSettings[$zoneId]);
            }
            if ($rest === 'email/routing/dns' && $method === 'DELETE') {
                foreach ($this->emailRoutingManagedDns[$zoneId] ?? [] as $recordId) {
                    unset($this->records[$zoneId][$recordId]);
                }
                $this->emailRoutingManagedDns[$zoneId] = [];
                $this->emailRoutingSettings[$zoneId]['enabled'] = false;
                $this->emailRoutingSettings[$zoneId]['status'] = 'unconfigured';

                return self::ok($this->emailRoutingSettings[$zoneId]);
            }
            if ($rest === 'email/routing/rules' && $method === 'GET') {
                $rows = array_values($this->emailRoutingRules[$zoneId]);

                return ['status' => 200, 'body' => json_encode([
                    'success' => true, 'errors' => [], 'result' => $rows,
                    'result_info' => ['page' => 1, 'per_page' => 50, 'total_pages' => 1, 'total_count' => count($rows)],
                ])];
            }
            if ($rest === 'email/routing/rules' && $method === 'POST') {
                $id = md5('email-rule' . ++$this->seq);
                $this->emailRoutingRules[$zoneId][$id] = $data + ['id' => $id, 'source' => 'api'];
                $this->emailRoutingRules[$zoneId][$id]['id'] = $id;

                return self::ok($this->emailRoutingRules[$zoneId][$id]);
            }
            if ($rest === 'email/routing/rules/catch_all' && $method === 'GET') {
                return self::ok($this->emailCatchAlls[$zoneId]);
            }
            if ($rest === 'email/routing/rules/catch_all' && $method === 'PUT') {
                $this->emailCatchAlls[$zoneId] = array_merge($this->emailCatchAlls[$zoneId], $data, ['id' => $this->emailCatchAlls[$zoneId]['id'], 'source' => 'api']);

                return self::ok($this->emailCatchAlls[$zoneId]);
            }
            if (preg_match('#^email/routing/rules/([A-Za-z0-9]{1,64})$#', $rest, $ruleMatch)) {
                $ruleId = $ruleMatch[1];
                if (!isset($this->emailRoutingRules[$zoneId][$ruleId])) {
                    return self::error(404, 81044, 'Routing rule does not exist.');
                }
                if ($method === 'GET') {
                    return self::ok($this->emailRoutingRules[$zoneId][$ruleId]);
                }
                if ($method === 'PUT') {
                    $this->emailRoutingRules[$zoneId][$ruleId] = array_merge($this->emailRoutingRules[$zoneId][$ruleId], $data, ['id' => $ruleId, 'source' => 'api']);

                    return self::ok($this->emailRoutingRules[$zoneId][$ruleId]);
                }
                if ($method === 'DELETE') {
                    unset($this->emailRoutingRules[$zoneId][$ruleId]);

                    return self::ok(['id' => $ruleId]);
                }
            }
        }
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

    private function ensureEmailRoutingState(string $zoneId): void
    {
        if (isset($this->emailRoutingSettings[$zoneId])) {
            return;
        }

        $name = strtolower(rtrim((string) ($this->zones[$zoneId]['name'] ?? 'example.com'), '.'));
        $this->emailRoutingSettings[$zoneId] = [
            'id' => md5('email-settings' . $zoneId),
            'enabled' => false,
            'name' => $name,
            'status' => 'unconfigured',
        ];
        $this->requiredEmailRoutingDns[$zoneId] = [
            ['type' => 'MX', 'name' => $name, 'content' => 'route1.mx.cloudflare.net', 'priority' => 10, 'ttl' => 1, 'proxied' => false],
            ['type' => 'MX', 'name' => $name, 'content' => 'route2.mx.cloudflare.net', 'priority' => 20, 'ttl' => 1, 'proxied' => false],
            ['type' => 'MX', 'name' => $name, 'content' => 'route3.mx.cloudflare.net', 'priority' => 30, 'ttl' => 1, 'proxied' => false],
            ['type' => 'TXT', 'name' => $name, 'content' => 'v=spf1 include:_spf.mx.cloudflare.net ~all', 'ttl' => 1, 'proxied' => false],
            ['type' => 'TXT', 'name' => 'cf2024-1._domainkey.' . $name, 'content' => 'v=DKIM1; h=sha256; k=rsa; p=FAKE_PUBLIC_KEY', 'ttl' => 1, 'proxied' => false],
        ];
        $this->emailRoutingRules[$zoneId] = [];
        $this->emailCatchAlls[$zoneId] = [
            'id' => md5('catch-all' . $zoneId),
            'actions' => [['type' => 'drop', 'value' => []]],
            'matchers' => [['type' => 'all']],
            'enabled' => false,
            'name' => 'Catch-All Rule',
            'source' => 'api',
        ];
        $this->emailRoutingManagedDns[$zoneId] = [];
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
