<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ServiceRepository;
use CodeVault\CpanelTools\CpanelToolsService;
use CodeVault\CpanelTools\CpanelUapiClient;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\ScriptedDatabase;
use CodeVault\View;
use ErrorException;
use PHPUnit\Framework\TestCase;

final class CpanelToolsServiceTest extends TestCase
{
    private const SERVICE_ID = 71;

    public function test_email_accounts_use_disk_listing_and_ftp_delete_uses_documented_parameters(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->uapiJson([
                [
                    'email' => 'sales@example.test',
                    'login' => 'sales@example.test',
                    'diskquota' => '100',
                    'diskused' => '12.5',
                ],
                [
                    'email' => 'Main Account',
                    'login' => 'cvuser1',
                    'diskquota' => 'unlimited',
                    'diskused' => 0,
                ],
            ])],
            ['status' => 200, 'body' => $this->uapiJson(null)],
        ]);
        $tools = $this->tools($http);

        $accounts = $tools->listEmailAccounts(self::SERVICE_ID);
        $this->assertTrue($accounts['success']);
        $this->assertSame('list_pops_with_disk', $this->query($http->requests[0]['url'])['cpanel_jsonapi_func']);
        $this->assertCount(2, $accounts['data']);
        $this->assertSame('100', $accounts['data'][0]['quota']);
        $this->assertSame('12.5', $accounts['data'][0]['used']);
        $this->assertTrue($accounts['data'][0]['deletable']);
        $this->assertFalse($accounts['data'][1]['deletable'], 'the cPanel primary account is not a mailbox that can be deleted');

        $deleted = $tools->deleteFtpAccount(self::SERVICE_ID, 'backup@files.example.test');
        $this->assertTrue($deleted['success']);
        $request = $http->requests[1];
        $query = $this->query($request['url']);
        $this->assertSame('delete_ftp', $query['cpanel_jsonapi_func']);
        $this->assertSame('backup', $query['user']);
        $this->assertSame('files.example.test', $query['domain']);
        $this->assertSame('0', $query['destroy']);
        $this->assertArrayNotHasKey('destroydir', $query);
    }

    public function test_softaculous_sso_falls_back_to_cpanel_home_when_app_session_fails(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => json_encode([
                'metadata' => ['result' => 0, 'reason' => 'Softaculous app key is unavailable.'],
                'data' => [],
            ], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => json_encode([
                'metadata' => ['result' => 1],
                'data' => ['url' => 'https://whm.example.test:2083/cpsess123/frontend/jupiter/index.html'],
            ], JSON_THROW_ON_ERROR)],
        ]);
        $tools = $this->tools($http);

        $result = $tools->ssoUrl(self::SERVICE_ID, 'softaculous');

        $this->assertTrue($result['success']);
        $this->assertSame('https://whm.example.test:2083/cpsess123/frontend/jupiter/index.html', $result['url']);
        $appRequest = $this->query($http->requests[0]['url']);
        $fallbackRequest = $this->query($http->requests[1]['url']);
        $this->assertSame('softaculous_instant', $appRequest['app']);
        $this->assertSame('cpaneld', $fallbackRequest['service']);
        $this->assertArrayNotHasKey('app', $fallbackRequest);
    }

    public function test_email_create_and_delete_send_a_local_part_and_matching_domain(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->uapiJson(null)],
            ['status' => 200, 'body' => $this->uapiJson(null)],
        ]);
        $tools = $this->tools($http);

        $this->assertTrue($tools->createEmailAccount(self::SERVICE_ID, 'billing', 'mail-pass', 500)['success']);
        $createRequest = $http->requests[0];
        $create = $this->requestParams($createRequest);
        $this->assertSame('POST', $createRequest['method']);
        $this->assertArrayNotHasKey('password', $this->query($createRequest['url']), 'mailbox passwords must not be sent in the URL');
        $this->assertSame('mail-pass', $create['password']);
        $this->assertSame('add_pop', $create['cpanel_jsonapi_func']);
        $this->assertSame('billing', $create['email']);
        $this->assertSame('example.test', $create['domain']);
        $this->assertSame('500', $create['quota']);

        $this->assertTrue($tools->deleteEmailAccount(self::SERVICE_ID, 'billing@example.test')['success']);
        $delete = $this->query($http->requests[1]['url']);
        $this->assertSame('delete_pop', $delete['cpanel_jsonapi_func']);
        $this->assertSame('billing', $delete['email']);
        $this->assertSame('example.test', $delete['domain']);

        $protected = $tools->deleteEmailAccount(self::SERVICE_ID, 'cvuser1@example.test');
        $this->assertFalse($protected['success'], 'the primary cPanel email account must be protected in the service layer');
        $this->assertCount(2, $http->requests, 'the primary account delete must not call cPanel');
    }

    public function test_ftp_listing_and_creation_match_cpanel_fields(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->uapiJson([
                ['user' => 'cvuser1', 'homedir' => '/home/cvuser1', 'type' => 'main'],
                ['user' => 'dev@files.example.test', 'homedir' => '/home/cvuser1/dev'],
            ])],
            ['status' => 200, 'body' => $this->uapiJson(null)],
        ]);
        $tools = $this->tools($http);

        $accounts = $tools->listFtpAccounts(self::SERVICE_ID);
        $this->assertTrue($accounts['success']);
        $this->assertSame('/home/cvuser1/dev', $accounts['data'][1]['homedir']);
        $this->assertFalse($accounts['data'][0]['deletable']);
        $this->assertTrue($accounts['data'][1]['deletable']);

        $protected = $tools->deleteFtpAccount(self::SERVICE_ID, 'cvuser1');
        $this->assertFalse($protected['success'], 'the primary FTP account must be protected in the service layer');
        $this->assertCount(1, $http->requests, 'the primary account delete must not call cPanel');

        $this->assertTrue($tools->createFtpAccount(self::SERVICE_ID, 'uploads', 'ftp-pass', 'public_html/uploads', 250)['success']);
        $createRequest = $http->requests[1];
        $query = $this->requestParams($createRequest);
        $this->assertSame('POST', $createRequest['method']);
        $this->assertArrayNotHasKey('pass', $this->query($createRequest['url']), 'FTP passwords must not be sent in the URL');
        $this->assertSame('add_ftp', $query['cpanel_jsonapi_func']);
        $this->assertSame('uploads', $query['user']);
        $this->assertSame('ftp-pass', $query['pass']);
        $this->assertSame('public_html/uploads', $query['homedir']);
        $this->assertSame('250', $query['quota']);
        $this->assertSame('example.test', $query['domain']);
    }

    public function test_mysql_list_create_and_delete_use_database_field_names(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->uapiJson([
                ['database' => 'cvuser_store', 'disk_usage' => 5, 'users' => ['cvuser_app']],
            ])],
            ['status' => 200, 'body' => $this->uapiJson(null)],
            ['status' => 200, 'body' => $this->uapiJson(null)],
        ]);
        $tools = $this->tools($http);

        $databases = $tools->listDatabases(self::SERVICE_ID);
        $this->assertSame('cvuser_store', $databases['data'][0]['database']);
        $this->assertTrue($tools->createDatabase(self::SERVICE_ID, 'cvuser_new')['success']);
        $this->assertTrue($tools->deleteDatabase(self::SERVICE_ID, 'cvuser_old')['success']);

        $create = $this->query($http->requests[1]['url']);
        $delete = $this->query($http->requests[2]['url']);
        $this->assertSame('Mysql', $create['cpanel_jsonapi_module']);
        $this->assertSame('create_database', $create['cpanel_jsonapi_func']);
        $this->assertSame('cvuser_new', $create['name']);
        $this->assertSame('delete_database', $delete['cpanel_jsonapi_func']);
        $this->assertSame('cvuser_old', $delete['name']);
    }

    public function test_database_and_dns_views_render_cpanel_listings_and_the_selected_zone(): void
    {
        $view = new View(dirname(__DIR__, 2) . '/resources/views');
        $base = [
            'service' => ['id' => self::SERVICE_ID, 'product_name' => 'Shared Hosting', 'domain' => 'example.test'],
            'listError' => null,
            'notice' => null,
        ];
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $databaseHtml = $view->render('cpanel-tools.index', $base + [
                'tab' => 'databases',
                'items' => [['database' => 'cvuser1_store']],
            ]);
            $dnsHtml = $view->render('cpanel-tools.index', $base + [
                'tab' => 'dns',
                'items' => [[
                    'line' => 5,
                    'name' => 'www.shop.example.test.',
                    'type' => 'A',
                    'ttl' => 14400,
                    'value' => '192.0.2.10',
                ]],
                'dnsZones' => ['example.test', 'shop.example.test'],
                'selectedDnsZone' => 'shop.example.test',
                'dnsRecordTypes' => ['A', 'AAAA', 'CNAME', 'TXT'],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('cvuser1_store', $databaseHtml, 'the MySQL page displays database names returned by cPanel');
        $this->assertStringContainsString('value="shop.example.test" selected', $dnsHtml);
        $this->assertStringContainsString('name="zone" value="shop.example.test"', $dnsHtml, 'DNS mutations retain the selected zone');
        $this->assertStringContainsString('www.shop.example.test.', $dnsHtml);
    }

    public function test_dns_listing_reads_all_cpanel_zones_then_fetches_the_selected_zone_records(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->dnsZonesJson(['example.test', 'shop.example.test'])],
            ['status' => 200, 'body' => $this->api2Json([[
                'statusmsg' => 'Zone Serialized',
                'record' => $this->dnsZone(),
            ]])],
        ]);
        $tools = $this->tools($http);

        $records = $tools->listDnsRecords(self::SERVICE_ID);

        $this->assertTrue($records['success']);
        $this->assertSame(['example.test', 'shop.example.test'], $records['zones']);
        $this->assertSame('example.test', $records['zone']);
        $zoneList = $this->query($http->requests[0]['url']);
        $zoneRecords = $this->query($http->requests[1]['url']);
        $this->assertSame('2', $zoneList['cpanel_jsonapi_apiversion']);
        $this->assertSame('ZoneEdit', $zoneList['cpanel_jsonapi_module']);
        $this->assertSame('fetchzones', $zoneList['cpanel_jsonapi_func']);
        $this->assertSame('2', $zoneRecords['cpanel_jsonapi_apiversion']);
        $this->assertSame('fetchzone', $zoneRecords['cpanel_jsonapi_func']);
        $this->assertSame('example.test', $zoneRecords['domain']);
        $this->assertSame(5, $records['data'][1]['line']);
        $this->assertSame('A', $records['data'][1]['type']);
        $this->assertSame('192.0.2.10', $records['data'][1]['value']);
    }

    public function test_dns_zone_selection_is_limited_to_domains_returned_by_cpanel(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->dnsZonesJson(['example.test', 'shop.example.test'])],
            ['status' => 200, 'body' => $this->api2Json([[
                'statusmsg' => 'Zone Serialized',
                'record' => $this->dnsZone(),
            ]])],
            ['status' => 200, 'body' => $this->dnsZonesJson(['example.test', 'shop.example.test'])],
        ]);
        $tools = $this->tools($http);

        $records = $tools->listDnsRecords(self::SERVICE_ID, 'shop.example.test');
        $this->assertTrue($records['success']);
        $this->assertSame('shop.example.test', $records['zone']);
        $this->assertSame('shop.example.test', $this->query($http->requests[1]['url'])['domain']);

        $foreignZone = $tools->listDnsRecords(self::SERVICE_ID, 'other-account.test');
        $this->assertFalse($foreignZone['success']);
        $this->assertCount(3, $http->requests, 'a zone not listed by cPanel is never fetched');
    }

    public function test_dns_add_uses_zoneedit_api2_with_record_specific_fields(): void
    {
        $http = new FakeHttpClient();
        $responses = [];
        $records = [
            ['www', 'A', '192.0.2.44', 14400],
            ['v6', 'AAAA', '2001:db8::44', 300],
            ['mail.example.test.', 'CNAME', 'target.example.net.', 600],
            ['_dmarc', 'TXT', 'v=DMARC1; p=none', 3600],
            ['@', 'A', '192.0.2.1', 600],
            ['@', 'CNAME', 'target.example.net.', 600],
        ];

        foreach ($records as $_record) {
            $responses[] = ['status' => 200, 'body' => $this->dnsZonesJson(['example.test'])];
            $responses[] = ['status' => 200, 'body' => $this->api2Json([['result' => ['status' => 1, 'statusmsg' => 'Zone updated']]])];
        }

        foreach (['.www.example.test.', 'www.', 'www.example.test..', 'outside.other.test.'] as $_invalidName) {
            $responses[] = ['status' => 200, 'body' => $this->dnsZonesJson(['example.test'])];
        }

        $http->respondInSequence($responses);
        $tools = $this->tools($http);

        $this->assertTrue($tools->addDnsRecord(self::SERVICE_ID, 'www', 'A', '192.0.2.44', 14400)['success']);
        $this->assertTrue($tools->addDnsRecord(self::SERVICE_ID, 'v6', 'AAAA', '2001:db8::44', 300)['success']);
        $this->assertTrue($tools->addDnsRecord(self::SERVICE_ID, 'mail.example.test.', 'CNAME', 'target.example.net.', 600)['success']);
        $this->assertTrue($tools->addDnsRecord(self::SERVICE_ID, '_dmarc', 'TXT', 'v=DMARC1; p=none', 3600)['success']);
        $this->assertTrue($tools->addDnsRecord(self::SERVICE_ID, '@', 'A', '192.0.2.1', 600)['success']);
        $this->assertTrue($tools->addDnsRecord(self::SERVICE_ID, '@', 'CNAME', 'target.example.net.', 600)['success']);

        $mutations = [];

        foreach ($http->requests as $request) {
            $query = $this->query($request['url']);

            if (($query['cpanel_jsonapi_func'] ?? '') === 'add_zone_record') {
                $mutations[] = $query;
            }
        }

        $a = $mutations[0];
        $aaaa = $mutations[1];
        $cname = $mutations[2];
        $txt = $mutations[3];
        $apex = $mutations[4];
        $apexCname = $mutations[5];
        $this->assertSame('2', $a['cpanel_jsonapi_apiversion']);
        $this->assertSame('ZoneEdit', $a['cpanel_jsonapi_module']);
        $this->assertSame('add_zone_record', $a['cpanel_jsonapi_func']);
        $this->assertSame('www', $a['name']);
        $this->assertSame('A', $a['type']);
        $this->assertSame('192.0.2.44', $a['address']);
        $this->assertSame('14400', $a['ttl']);
        $this->assertSame('IN', $a['class']);
        $this->assertSame('2001:db8::44', $aaaa['address']);
        $this->assertSame('mail', $cname['name'], 'full names inside the zone are sent as relative labels');
        $this->assertSame('target.example.net.', $cname['cname']);
        $this->assertSame('v=DMARC1; p=none', $txt['txtdata']);
        $this->assertSame('example.test', $apex['name'], 'the apex name resolves to the service domain');
        $this->assertSame('example.test', $apexCname['name']);
        $this->assertSame('1', $apexCname['flatten'], 'cPanel requires the flatten option for an apex CNAME');
        $this->assertArrayNotHasKey('mass_edit_zone', $a);

        foreach (['.www.example.test.', 'www.', 'www.example.test..', 'outside.other.test.'] as $invalidName) {
            $invalid = $tools->addDnsRecord(self::SERVICE_ID, $invalidName, 'A', '192.0.2.99', 600);
            $this->assertFalse($invalid['success'], 'malformed or out-of-zone DNS names must be rejected');
        }

        $this->assertCount(6, $mutations, 'invalid names must not issue a DNS mutation');
        $this->assertCount(16, $http->requests, 'each valid operation checks cPanel zones before adding, and invalid names only check the zones');
    }

    public function test_dns_delete_uses_api2_line_number_and_rejects_stale_rows(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->dnsZonesJson(['example.test'])],
            ['status' => 200, 'body' => $this->api2Json([['statusmsg' => 'Zone Serialized', 'record' => $this->dnsZone()]])],
            ['status' => 200, 'body' => $this->api2Json([['result' => ['status' => 1, 'statusmsg' => 'Zone updated']]])],
            ['status' => 200, 'body' => $this->dnsZonesJson(['example.test'])],
            ['status' => 200, 'body' => $this->api2Json([['statusmsg' => 'Zone Serialized', 'record' => $this->dnsZone('192.0.2.99')]])],
        ]);
        $tools = $this->tools($http);

        $deleted = $tools->deleteDnsRecord(self::SERVICE_ID, 5, 'www.example.test.', 'A', '192.0.2.10');
        $this->assertTrue($deleted['success']);
        $query = $this->query($http->requests[2]['url']);
        $this->assertSame('ZoneEdit', $query['cpanel_jsonapi_module']);
        $this->assertSame('remove_zone_record', $query['cpanel_jsonapi_func']);
        $this->assertSame('example.test', $query['domain']);
        $this->assertSame('5', $query['line']);

        $stale = $tools->deleteDnsRecord(self::SERVICE_ID, 5, 'www.example.test.', 'A', '192.0.2.10');
        $this->assertFalse($stale['success']);
        $this->assertStringContainsString('changed since the page loaded', $stale['message']);
        $this->assertCount(5, $http->requests, 'a stale row must not issue a DNS mutation');
    }

    public function test_domains_use_documented_api_versions_and_addon_domain_key_for_deletion(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->api2Json([
                ['domain' => 'shop.example.test', 'domainkey' => 'shop_example.test', 'dir' => '/home/cvuser1/public_html/shop'],
            ])],
            ['status' => 200, 'body' => $this->api2Json([['result' => 1, 'reason' => 'Subdomain created']])],
            ['status' => 200, 'body' => $this->api2Json([['result' => 1, 'reason' => 'Subdomain removed']])],
            ['status' => 200, 'body' => $this->uapiJson(null)],
            ['status' => 200, 'body' => $this->uapiJson([
                ['domain' => 'addon.example.test', 'domainkey' => 'addon_example.test', 'subdomain' => 'addon'],
            ])],
            ['status' => 200, 'body' => $this->uapiJson(null)],
        ]);
        $tools = $this->tools($http);

        $subdomains = $tools->listSubdomains(self::SERVICE_ID);
        $this->assertSame('shop.example.test', $subdomains['data'][0]['domain']);
        $listQuery = $this->query($http->requests[0]['url']);
        $this->assertSame('2', $listQuery['cpanel_jsonapi_apiversion']);
        $this->assertSame('listsubdomains', $listQuery['cpanel_jsonapi_func']);

        $this->assertTrue($tools->createSubdomain(self::SERVICE_ID, 'shop', '', '')['success']);
        $createQuery = $this->query($http->requests[1]['url']);
        $this->assertSame('2', $createQuery['cpanel_jsonapi_apiversion']);
        $this->assertSame('SubDomain', $createQuery['cpanel_jsonapi_module']);
        $this->assertSame('addsubdomain', $createQuery['cpanel_jsonapi_func']);
        $this->assertSame('shop', $createQuery['domain']);
        $this->assertSame('example.test', $createQuery['rootdomain']);
        $this->assertArrayNotHasKey('dir', $createQuery);

        $this->assertTrue($tools->deleteSubdomain(self::SERVICE_ID, 'shop.example.test')['success']);
        $deleteQuery = $this->query($http->requests[2]['url']);
        $this->assertSame('2', $deleteQuery['cpanel_jsonapi_apiversion']);
        $this->assertSame('delsubdomain', $deleteQuery['cpanel_jsonapi_func']);
        $this->assertSame('shop.example.test', $deleteQuery['domain']);
        $this->assertArrayNotHasKey('keepdir', $deleteQuery);

        $this->assertTrue($tools->createAddonDomain(self::SERVICE_ID, 'addon.example.test', 'addon', '')['success']);
        $addonQuery = $this->query($http->requests[3]['url']);
        $this->assertSame('3', $addonQuery['cpanel_jsonapi_apiversion']);
        $this->assertSame('addaddondomain', $addonQuery['cpanel_jsonapi_func']);
        $this->assertSame('addon.example.test', $addonQuery['newdomain']);
        $this->assertSame('addon', $addonQuery['subdomain']);
        $this->assertArrayNotHasKey('dir', $addonQuery);

        $addons = $tools->listAddonDomains(self::SERVICE_ID);
        $this->assertSame('addon_example.test', $addons['data'][0]['domainkey']);
        $this->assertSame('listaddondomains', $this->query($http->requests[4]['url'])['cpanel_jsonapi_func']);

        $this->assertTrue($tools->deleteAddonDomain(self::SERVICE_ID, 'addon.example.test', $addons['data'][0]['domainkey'])['success']);
        $addonDelete = $this->query($http->requests[5]['url']);
        $this->assertSame('deladdondomain', $addonDelete['cpanel_jsonapi_func']);
        $this->assertSame('addon.example.test', $addonDelete['domain']);
        $this->assertSame('addon_example.test', $addonDelete['subdomain']);
    }

    public function test_forwarders_autoresponders_and_redirects_use_documented_names_and_fields(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->uapiJson([
                ['dest' => 'sales@example.test', 'forward' => 'team@elsewhere.test'],
            ])],
            ['status' => 200, 'body' => $this->uapiJson(null)],
            ['status' => 200, 'body' => $this->uapiJson([
                ['email' => 'away@example.test', 'subject' => 'Out of office'],
            ])],
            ['status' => 200, 'body' => $this->uapiJson(null)],
            ['status' => 200, 'body' => $this->uapiJson([
                ['domain' => 'example.test', 'sourceurl' => '/old', 'targeturl' => 'https://example.test/new', 'type' => 'permanent'],
            ])],
            ['status' => 200, 'body' => $this->uapiJson(null)],
            ['status' => 200, 'body' => $this->uapiJson(null)],
            ['status' => 200, 'body' => $this->uapiJson(null)],
        ]);
        $tools = $this->tools($http);

        $forwarders = $tools->listForwarders(self::SERVICE_ID);
        $this->assertSame('sales@example.test', $forwarders['data'][0]['source']);
        $this->assertSame('team@elsewhere.test', $forwarders['data'][0]['destination']);
        $this->assertTrue($tools->createForwarder(self::SERVICE_ID, 'info', 'team@elsewhere.test')['success']);
        $forwardQuery = $this->query($http->requests[1]['url']);
        $this->assertSame('add_forwarder', $forwardQuery['cpanel_jsonapi_func']);
        $this->assertSame('info@example.test', $forwardQuery['email']);
        $this->assertSame('example.test', $forwardQuery['domain']);
        $this->assertSame('team@elsewhere.test', $forwardQuery['fwdemail']);

        $responders = $tools->listAutoresponders(self::SERVICE_ID);
        $this->assertSame('away@example.test', $responders['data'][0]['email']);
        $stop = time() + 7200;
        $this->assertTrue($tools->createAutoresponder(self::SERVICE_ID, 'away', 'Out', 'I am away.', $stop)['success']);
        $responderRequest = $http->requests[3];
        $responderQuery = $this->requestParams($responderRequest);
        $this->assertSame('POST', $responderRequest['method']);
        $this->assertSame('add_auto_responder', $responderQuery['cpanel_jsonapi_func']);
        $this->assertSame('24', $responderQuery['interval']);
        $this->assertSame('0', $responderQuery['is_html']);
        $this->assertArrayNotHasKey('html', $responderQuery);
        $this->assertSame('away', $responderQuery['email']);
        $this->assertSame('example.test', $responderQuery['domain']);
        $this->assertSame((string) $stop, $responderQuery['stop']);
        $this->assertGreaterThan(0, (int) $responderQuery['start']);

        $redirects = $tools->listRedirects(self::SERVICE_ID);
        $this->assertSame('/old', $redirects['data'][0]['source']);
        $this->assertSame('https://example.test/new', $redirects['data'][0]['dest']);
        $this->assertTrue($tools->createRedirect(self::SERVICE_ID, '/legacy', 'https://example.test/new', 'temp')['success']);
        $redirectQuery = $this->query($http->requests[5]['url']);
        $this->assertSame('Mime', $redirectQuery['cpanel_jsonapi_module']);
        $this->assertSame('add_redirect', $redirectQuery['cpanel_jsonapi_func']);
        $this->assertSame('/legacy', $redirectQuery['src']);
        $this->assertSame('https://example.test/new', $redirectQuery['redirect']);
        $this->assertSame('temp', $redirectQuery['type']);
        $this->assertSame('0', $redirectQuery['redirect_www']);
        $this->assertSame('0', $redirectQuery['redirect_wildcard']);

        $this->assertTrue($tools->deleteRedirect(self::SERVICE_ID, 'example.test', '/old')['success']);
        $deleteRedirectQuery = $this->query($http->requests[6]['url']);
        $this->assertSame('Mime', $deleteRedirectQuery['cpanel_jsonapi_module']);
        $this->assertSame('delete_redirect', $deleteRedirectQuery['cpanel_jsonapi_func']);
        $this->assertSame('example.test', $deleteRedirectQuery['domain']);
        $this->assertSame('/old', $deleteRedirectQuery['src']);

        $this->assertTrue($tools->deleteAutoresponder(self::SERVICE_ID, 'away@example.test')['success']);
        $deleteAutoresponderQuery = $this->query($http->requests[7]['url']);
        $this->assertSame('delete_auto_responder', $deleteAutoresponderQuery['cpanel_jsonapi_func']);
        $this->assertSame('away@example.test', $deleteAutoresponderQuery['email']);
        $this->assertArrayNotHasKey('domain', $deleteAutoresponderQuery);
    }

    public function test_cron_and_ssh_operations_use_api2_and_documented_parameter_names(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->api2Json([
                ['count' => 1, 'minute' => '0', 'hour' => '1', 'day' => '*', 'month' => '*', 'weekday' => '*', 'command' => '/usr/bin/php job.php'],
            ])],
            ['status' => 200, 'body' => $this->api2Json([['status' => 1, 'statusmsg' => 'crontab installed']])],
            ['status' => 200, 'body' => $this->api2Json([
                ['name' => 'id_rsa', 'authstatus' => 0],
                ['name' => 'deploy', 'authstatus' => 1],
            ])],
            ['status' => 200, 'body' => $this->api2Json([['reason' => 'key generated', 'result' => 1]])],
            ['status' => 200, 'body' => $this->api2Json([['name' => 'deploy.pub', 'status' => 'authorized']])],
        ]);
        $tools = $this->tools($http);

        $jobs = $tools->listCronJobs(self::SERVICE_ID);
        $this->assertSame(1, $jobs['data'][0]['line']);
        $this->assertSame('/usr/bin/php job.php', $jobs['data'][0]['command']);
        $this->assertTrue($tools->createCronJob(self::SERVICE_ID, '*/5', '*', '*', '*', '*', '/usr/bin/php job.php')['success']);

        $keys = $tools->listSshKeys(self::SERVICE_ID);
        $this->assertFalse($keys['data'][0]['authorized']);
        $this->assertTrue($keys['data'][1]['authorized']);
        $this->assertTrue($tools->generateSshKey(self::SERVICE_ID, 'deploy', 'strong-passphrase', 4096)['success']);
        $this->assertTrue($tools->authorizeSshKey(self::SERVICE_ID, 'deploy')['success']);

        $cronList = $this->query($http->requests[0]['url']);
        $cronRequest = $http->requests[1];
        $cronCreate = $this->requestParams($cronRequest);
        $sshList = $this->query($http->requests[2]['url']);
        $sshGenerateRequest = $http->requests[3];
        $sshGenerate = $this->requestParams($sshGenerateRequest);
        $sshAuthorize = $this->query($http->requests[4]['url']);

        $this->assertSame('POST', $cronRequest['method']);
        $this->assertArrayNotHasKey('command', $this->query($cronRequest['url']), 'cron command text must not be sent in the URL');
        $this->assertSame('POST', $sshGenerateRequest['method']);
        $this->assertArrayNotHasKey('pass', $this->query($sshGenerateRequest['url']), 'SSH passphrases must not be sent in the URL');

        $this->assertSame('2', $cronList['cpanel_jsonapi_apiversion']);
        $this->assertSame('listcron', $cronList['cpanel_jsonapi_func']);
        $this->assertSame('2', $cronCreate['cpanel_jsonapi_apiversion']);
        $this->assertSame('add_line', $cronCreate['cpanel_jsonapi_func']);
        $this->assertSame('*/5', $cronCreate['minute']);
        $this->assertSame('2', $sshList['cpanel_jsonapi_apiversion']);
        $this->assertSame('listkeys', $sshList['cpanel_jsonapi_func']);
        $this->assertArrayNotHasKey('type', $sshList, 'listkeys is called without undocumented filters');
        $this->assertSame('genkey', $sshGenerate['cpanel_jsonapi_func']);
        $this->assertSame('4096', $sshGenerate['bits']);
        $this->assertSame('deploy', $sshGenerate['name']);
        $this->assertSame('strong-passphrase', $sshGenerate['pass']);
        $this->assertSame('rsa', $sshGenerate['type']);
        $this->assertArrayNotHasKey('password', $sshGenerate);
        $this->assertSame('authkey', $sshAuthorize['cpanel_jsonapi_func']);
        $this->assertSame('deploy', $sshAuthorize['key']);
        $this->assertSame('authorize', $sshAuthorize['action']);
        $this->assertArrayNotHasKey('name', $sshAuthorize);
    }

    public function test_ssh_missing_api2_function_is_reported_as_a_server_capability_issue(): void
    {
        $http = new FakeHttpClient();
        $http->respondWith(200, json_encode([
            'cpanelresult' => [
                'apiversion' => 2,
                'data' => [],
                'error' => 'The system could not find function "listkeys" in module "SSH".',
                'event' => ['result' => 0],
                'module' => 'SSH',
            ],
        ], JSON_THROW_ON_ERROR));

        $keys = $this->tools($http)->listSshKeys(self::SERVICE_ID);

        $this->assertFalse($keys['success']);
        $this->assertStringContainsString('does not expose API 2 SSH::listkeys', $keys['message']);
        $this->assertCount(1, $http->requests, 'there is no documented UAPI list_keys operation to blindly retry');
    }

    public function test_ssl_uses_list_certs_and_post_install_and_disk_usage_reads_singular_limit_key(): void
    {
        $http = new FakeHttpClient();
        $http->respondInSequence([
            ['status' => 200, 'body' => $this->uapiJson([
                [
                    'domains' => ['example.test', 'www.example.test'],
                    'issuer.organizationName' => "Let's Encrypt",
                    'not_after' => (string) (time() + 86400),
                    'domain_is_configured' => 1,
                ],
                [
                    'domains' => ['staging.example.test'],
                    'issuer.organizationName' => 'Example CA',
                    'not_after' => (string) (time() + 86400),
                    'domain_is_configured' => 0,
                ],
            ])],
            ['status' => 200, 'body' => $this->uapiJson(null)],
            ['status' => 200, 'body' => $this->uapiJson(['megabytes_used' => '123.5', 'megabyte_limit' => '2048'])],
            ['status' => 200, 'body' => $this->uapiJson(['megabytes_used' => 123])],
        ]);
        $tools = $this->tools($http);

        $certificates = $tools->listSslCertificates(self::SERVICE_ID);
        $this->assertTrue($certificates['success']);
        $this->assertSame('list_certs', $this->query($http->requests[0]['url'])['cpanel_jsonapi_func']);
        $this->assertSame('example.test, www.example.test', $certificates['data'][0]['domain']);
        $this->assertSame("Let's Encrypt", $certificates['data'][0]['issuer']);
        $this->assertNotSame('', $certificates['data'][0]['expires']);
        $this->assertTrue($certificates['data'][0]['installed']);
        $this->assertFalse($certificates['data'][1]['installed']);

        $privateKey = "-----BEGIN PRIVATE KEY-----\nsecret-key\n-----END PRIVATE KEY-----";
        $this->assertTrue($tools->installSslCertificate(self::SERVICE_ID, 'example.test', 'certificate', $privateKey, 'ca-bundle')['success']);
        $installRequest = $http->requests[1];
        $this->assertSame('POST', $installRequest['method']);
        $this->assertStringNotContainsString('secret-key', $installRequest['url']);
        parse_str((string) $installRequest['body'], $postBody);
        $this->assertSame($privateKey, $postBody['key']);
        $this->assertSame('install_ssl', $postBody['cpanel_jsonapi_func']);
        $this->assertSame('1', $postBody['api_version']);

        $usage = $tools->diskUsage(self::SERVICE_ID);
        $this->assertTrue($usage['success']);
        $this->assertSame(123.5, $usage['data']['usedMb']);
        $this->assertSame(2048.0, $usage['data']['limitMb']);

        $badUsage = $tools->diskUsage(self::SERVICE_ID);
        $this->assertFalse($badUsage['success']);
        $this->assertSame([], $badUsage['data']);
        $quotaQuery = $this->query($http->requests[2]['url']);
        $this->assertSame('get_quota_info', $quotaQuery['cpanel_jsonapi_func']);
    }

    public function test_failed_or_malformed_quota_data_does_not_render_as_zero_usage(): void
    {
        $http = new FakeHttpClient();
        $http->respondWith(200, $this->uapiJson(['megabytes_used' => 0]));
        $usage = $this->tools($http)->diskUsage(self::SERVICE_ID);
        $this->assertFalse($usage['success']);

        $view = new View(dirname(__DIR__, 2) . '/resources/views');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $html = $view->render('cpanel-tools.index', [
                'service' => ['id' => self::SERVICE_ID, 'product_name' => 'Shared Hosting', 'domain' => 'example.test'],
                'tab' => 'usage',
                'items' => [],
                'listError' => null,
                'notice' => null,
                'dnsRecordTypes' => [],
                'forwarders' => [],
                'autoresponders' => [],
                'addonDomains' => [],
                'subdomains' => [],
                'redirects' => [],
                'usage' => [],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('Usage data is unavailable.', $html);
    }

    private function tools(FakeHttpClient $http): CpanelToolsService
    {
        $db = new ScriptedDatabase();
        $db->on('~FROM services s~', [[
            'id' => self::SERVICE_ID,
            'client_id' => 8,
            'server_id' => 4,
            'username' => 'cvuser1',
            'domain' => 'example.test',
            'status' => 'active',
            'product_name' => 'Shared Hosting',
        ]]);
        $db->on('~FROM servers WHERE id =~', [[
            'id' => 4,
            'hostname' => 'whm.example.test',
            'module_slug' => 'cpanel',
            'api_username' => 'root',
            'api_token' => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0',
            'api_port' => 2087,
            'use_ssl' => 1,
        ]]);

        return new CpanelToolsService(
            new ServiceRepository($db),
            new ServerRepository($db),
            new CpanelUapiClient($http)
        );
    }

    /** @param mixed $data */
    private function uapiJson(mixed $data): string
    {
        return json_encode(['result' => ['status' => 1, 'errors' => null, 'data' => $data]], JSON_THROW_ON_ERROR);
    }

    /** @param array<int, array<string, mixed>> $data */
    private function api2Json(array $data): string
    {
        return json_encode([
            'cpanelresult' => [
                'apiversion' => 2,
                'data' => $data,
                'event' => ['result' => 1],
                'module' => 'cPanel',
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<int, string> $domains */
    private function dnsZonesJson(array $domains): string
    {
        $zones = [];

        foreach ($domains as $domain) {
            $zones[$domain] = [];
        }

        return $this->api2Json([[
            'status' => 1,
            'statusmsg' => 'Zones fetched',
            'zones' => $zones,
        ]]);
    }

    /** @return array<int, array<string, mixed>> */
    private function dnsZone(string $address = '192.0.2.10'): array
    {
        return [
            [
                'name' => 'example.test.',
                'type' => 'SOA',
                'Line' => 1,
                'line' => 1,
                'ttl' => '3600',
                'serial' => '2026101101',
                'mname' => 'ns1.example.test.',
                'rname' => 'hostmaster.example.test.',
            ],
            [
                'name' => 'www.example.test.',
                'type' => 'A',
                'Line' => 5,
                'line' => 5,
                'ttl' => '14400',
                'address' => $address,
                'record' => $address,
                'class' => 'IN',
            ],
        ];
    }

    /** @param array{url: string, body?: ?string} $request @return array<string, mixed> */
    private function requestParams(array $request): array
    {
        $query = $this->query($request['url']);
        $body = [];

        if (is_string($request['body'] ?? null)) {
            parse_str($request['body'], $body);
        }

        return array_merge($query, $body);
    }

    /** @return array<string, string> */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
