<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Domains\ConnectResellerRegistrarModule;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Implemented against CR_API_Document_V11.pdf (real ConnectReseller API
 * docs, provided by the user) — every response fixture below mirrors the
 * document's actual envelope shape: {"responseMsg": {message, statusCode,
 * reason}, "responseData": {...}}.
 */
final class ConnectResellerRegistrarModuleTest extends TestCase
{
    private FakeHttpClient $http;
    private ConnectResellerRegistrarModule $module;

    /** @var array<string, mixed> */
    private array $registrar = ['api_key' => 'ZvSNmbNAKaL2Cah'];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->module = new ConnectResellerRegistrarModule($this->http);
    }

    public function test_check_availability_hits_the_documented_endpoint_and_reports_available(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"domainType":"Standard","available":true,"registrationfees":10.99}}');

        $result = $this->module->checkAvailability(['domain' => 'example.com', 'registrar' => $this->registrar]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/checkdomainavailable?', $request['url']);
        $this->assertStringContainsString('APIKey=ZvSNmbNAKaL2Cah', $request['url']);
        $this->assertStringContainsString('websiteName=example.com', $request['url']);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['available']);
    }

    public function test_check_availability_treats_statuscode_400_as_a_successful_not_available_check(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Not available","statusCode":400},"responseData":{"available":false}}');

        $result = $this->module->checkAvailability(['domain' => 'taken.com', 'registrar' => $this->registrar]);

        $this->assertTrue($result['success'], '400 means "not available", not a failed lookup');
        $this->assertFalse($result['available']);
    }

    public function test_register_hits_domainorder_then_looks_up_the_domain_id_via_viewdomain(): void
    {
        // registrarClientId supplied directly so this test isolates the
        // domainorder+ViewDomain flow — customer auto-creation is covered
        // by its own test below.
        // Same canned response answers both calls FakeHttpClient sees; it
        // carries every field either call needs (register's own fields
        // plus the domainNameId only ViewDomain's real response has).
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"creationDate":"2026-01-01","expiryDate":"2028-01-01","domainNameId":555,"msg":"ok","msgCode":"1000","name":"newdomain.com"}}');

        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 2,
            'nameservers' => ['ns1.test', 'ns2.test'],
            'registrarClientId' => '42',
            'registrar' => $this->registrar,
        ]);

        $this->assertCount(2, $this->http->requests);
        $orderRequest = $this->http->requests[0];
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/domainorder?', $orderRequest['url']);
        $this->assertStringContainsString('ProductType=1', $orderRequest['url']);
        $this->assertStringContainsString('Websitename=newdomain.com', $orderRequest['url']);
        $this->assertStringContainsString('Duration=2', $orderRequest['url']);
        $this->assertStringContainsString('ns1=ns1.test', $orderRequest['url']);
        $this->assertStringContainsString('Id=42', $orderRequest['url']);

        $lookupRequest = $this->http->requests[1];
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/ViewDomain?', $lookupRequest['url']);
        $this->assertStringContainsString('websiteName=newdomain.com', $lookupRequest['url']);

        $this->assertTrue($result['success']);
        $this->assertSame('2026-01-01', $result['registrationDate']);
        $this->assertSame('2028-01-01', $result['expiryDate']);
        $this->assertSame('555', $result['registrarDomainId']);
        $this->assertArrayNotHasKey('registrarClientId', $result, 'a pre-supplied customer ID should not be reported back as newly created');
    }

    public function test_register_creates_a_connectreseller_customer_when_none_exists_yet(): void
    {
        // The lookup has to MISS before a create makes sense, so this needs a
        // per-call script rather than one canned response.
        $found = '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"clientId":777,"creationDate":"2026-01-01","expiryDate":"2028-01-01","domainNameId":555}}';

        $this->http->respondInSequence([
            // ViewClient — no such customer yet.
            ['status' => 200, 'body' => '{"responseMsg":{"message":"Not found","statusCode":400},"responseData":null}'],
            // AddClient
            ['status' => 200, 'body' => '{"responseMsg":{"message":"Success","statusCode":200},"responseData":null}'],
            // ViewClient again — AddClient never returns the ID it created.
            ['status' => 200, 'body' => $found],
            // domainorder, then ViewDomain
            ['status' => 200, 'body' => $found],
            ['status' => 200, 'body' => $found],
        ]);

        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 1,
            'client' => ['email' => 'buyer@example.test', 'first_name' => 'Buyer', 'last_name' => 'Person'],
            'registrar' => $this->registrar,
        ]);

        $this->assertCount(5, $this->http->requests, 'ViewClient (miss), AddClient, ViewClient, domainorder, ViewDomain');
        $this->assertStringContainsString('/ViewClient?', $this->http->requests[0]['url']);
        $this->assertStringContainsString('/AddClient?', $this->http->requests[1]['url']);
        $this->assertStringContainsString('UserName=buyer%40example.test', $this->http->requests[1]['url']);
        $this->assertStringContainsString('/ViewClient?', $this->http->requests[2]['url']);
        $this->assertStringContainsString('/domainorder?', $this->http->requests[3]['url']);
        $this->assertStringContainsString('Id=777', $this->http->requests[3]['url']);

        $this->assertTrue($result['success']);
        $this->assertSame('777', $result['registrarClientId']);
    }

    public function test_register_reuses_an_existing_registrar_customer_instead_of_creating_a_duplicate(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"clientId":777,"creationDate":"2026-01-01","expiryDate":"2028-01-01","domainNameId":555}}');

        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 1,
            'client' => ['email' => 'buyer@example.test', 'first_name' => 'Buyer', 'last_name' => 'Person'],
            'registrar' => $this->registrar,
        ]);

        $urls = array_column($this->http->requests, 'url');
        $this->assertSame([], array_values(array_filter($urls, static fn (string $u): bool => str_contains($u, '/AddClient?'))), 'a customer that already exists must never be re-created');

        $this->assertTrue($result['success']);
        $this->assertSame('777', $result['registrarClientId']);
    }

    public function test_register_fails_clearly_when_the_client_has_no_email_to_create_a_customer_with(): void
    {
        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 1,
            'client' => [],
            'registrar' => $this->registrar,
        ]);

        $this->assertFalse($result['success']);
        $this->assertCount(0, $this->http->requests);
    }

    public function test_register_reports_failure_from_the_documented_envelope_without_a_lookup_call(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Domain already registered","statusCode":400},"responseData":null}');

        $result = $this->module->register(['domain' => 'taken.com', 'years' => 1, 'registrarClientId' => '1', 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertSame('Domain already registered', $result['message']);
        $this->assertCount(1, $this->http->requests, 'a failed order must not trigger the follow-up ViewDomain lookup');
    }

    public function test_transfer_hits_transferorder_with_documented_params(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Transfer initiated","statusCode":200},"responseData":null}');

        $result = $this->module->transfer([
            'domain' => 'transferme.com',
            'eppCode' => 'AUTH123',
            'registrarClientId' => '42',
            'registrar' => $this->registrar,
        ]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/TransferOrder?', $request['url']);
        $this->assertStringContainsString('OrderType=4', $request['url']);
        $this->assertStringContainsString('AuthCode=AUTH123', $request['url']);
        $this->assertStringContainsString('Id=42', $request['url']);
        $this->assertTrue($result['success']);
    }

    public function test_renew_hits_renewalorder_and_returns_the_new_expiry(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Renewed","statusCode":200},"responseData":{"expiryDate":"2029-01-01","domainName":"renewme.com"}}');

        $result = $this->module->renew(['domain' => 'renewme.com', 'years' => 1, 'registrarClientId' => '42', 'registrar' => $this->registrar]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/RenewalOrder?', $request['url']);
        $this->assertStringContainsString('OrderType=2', $request['url']);
        $this->assertStringContainsString('Id=42', $request['url']);
        $this->assertTrue($result['success']);
        $this->assertSame('2029-01-01', $result['expiryDate']);
    }

    public function test_renew_fails_clearly_when_there_is_no_email_to_resolve_a_customer_from(): void
    {
        $result = $this->module->renew(['domain' => 'renewme.com', 'years' => 1, 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no email on file', $result['message']);
        $this->assertCount(0, $this->http->requests);
    }

    /**
     * The production failure: a domain imported from another system renews
     * fine at the registrar, but no `registrar_client_id` was ever stored
     * locally. renew() used to give up with "Missing ConnectReseller customer
     * ID"; it now resolves the existing customer by email and proceeds.
     */
    public function test_renew_resolves_an_unlinked_customer_by_email_instead_of_failing(): void
    {
        $this->http->respondInSequence([
            // ViewClient — the customer already exists at ConnectReseller.
            ['status' => 200, 'body' => '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"clientId":777}}'],
            // RenewalOrder
            ['status' => 200, 'body' => '{"responseMsg":{"message":"Renewed","statusCode":200},"responseData":{"expiryDate":"2029-01-01"}}'],
        ]);

        $result = $this->module->renew([
            'domain' => 'imported.com',
            'years' => 1,
            'client' => ['email' => 'owner@example.test', 'first_name' => 'Owner', 'last_name' => 'Person'],
            'registrar' => $this->registrar,
        ]);

        $this->assertCount(2, $this->http->requests, 'ViewClient then RenewalOrder — no AddClient for a customer that already exists');
        $this->assertStringContainsString('/ViewClient?', $this->http->requests[0]['url']);
        $this->assertStringContainsString('/RenewalOrder?', $this->http->requests[1]['url']);
        $this->assertStringContainsString('Id=777', $this->http->requests[1]['url']);

        $this->assertTrue($result['success']);
        $this->assertSame('2029-01-01', $result['expiryDate']);
        $this->assertSame('777', $result['registrarClientId'], 'the resolved ID must come back so the client row gets linked');
    }

    public function test_get_nameservers_parses_the_numbered_fields_from_viewdomain(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"nameserver1":"ns1.test","nameserver2":"ns2.test","nameserver3":"","domainNameId":10}}');

        $result = $this->module->getNameservers(['domain' => 'nsdomain.com', 'registrar' => $this->registrar]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/ViewDomain?', $request['url']);
        $this->assertTrue($result['success']);
        $this->assertSame(['ns1.test', 'ns2.test'], $result['nameservers']);
    }

    public function test_save_nameservers_fails_after_trying_to_resolve_an_unknown_domain_id(): void
    {
        // ensureDomainId() looks the domainNameId up via ViewDomain when it
        // isn't already known, so exactly one call is expected before giving
        // up — not zero, as this asserted before that lookup was added.
        $result = $this->module->saveNameservers(['domain' => 'nsdomain.com', 'nameservers' => ['ns1.test'], 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertCount(1, $this->http->requests);
        $this->assertStringContainsString('/ViewDomain?', $this->http->requests[0]['url']);
    }

    public function test_save_nameservers_hits_updatenameserver_when_the_domain_id_is_known(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Updated","statusCode":200},"responseData":{"msg":"ok","msgCode":"1000"}}');

        $result = $this->module->saveNameservers([
            'domain' => 'nsdomain.com',
            'nameservers' => ['ns1.new', 'ns2.new'],
            'registrarDomainId' => '10',
            'registrar' => $this->registrar,
        ]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/UpdateNameServer?', $request['url']);
        $this->assertStringContainsString('domainNameId=10', $request['url']);
        $this->assertStringContainsString('nameServer1=ns1.new', $request['url']);
        $this->assertTrue($result['success']);
    }

    public function test_get_registrar_lock_reads_isdomainlocked_from_viewdomain(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"isDomainLocked":true}}');

        $result = $this->module->getRegistrarLock(['domain' => 'lockdomain.com', 'registrar' => $this->registrar]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['locked']);
    }

    public function test_set_registrar_lock_hits_managedomainlock_with_the_domain_id(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Locked","statusCode":200},"responseData":null}');

        $result = $this->module->setRegistrarLock([
            'domain' => 'lockdomain.com',
            'lock' => true,
            'registrarDomainId' => '99',
            'registrar' => $this->registrar,
        ]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/ManageDomainLock?', $request['url']);
        $this->assertStringContainsString('domainNameId=99', $request['url']);
        $this->assertStringContainsString('isDomainLocked=true', $request['url']);
        $this->assertTrue($result['success']);
    }

    public function test_enable_id_protection_hits_manageprivacyprotection_with_lowercase_param_matching_the_doc_example(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Enabled","statusCode":200},"responseData":null}');

        $result = $this->module->enableIdProtection([
            'domain' => 'protectdomain.com',
            'registrarDomainId' => '77',
            'registrar' => $this->registrar,
        ]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/ManageDomainPrivacyProtection?', $request['url']);
        $this->assertStringContainsString('iswhoisprotected=true', $request['url']);
        $this->assertTrue($result['success']);
    }

    public function test_get_epp_code_handles_a_bare_string_response(): void
    {
        $this->http->respondWith(200, '"ABC123SECRET"');

        $result = $this->module->getEppCode(['domain' => 'eppdomain.com', 'registrarDomainId' => '5', 'registrar' => $this->registrar]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/ViewEPPCode?', $request['url']);
        $this->assertStringContainsString('domainNameId=5', $request['url']);
        $this->assertTrue($result['success']);
        $this->assertSame('ABC123SECRET', $result['eppCode']);
    }

    public function test_get_epp_code_fails_after_trying_to_resolve_an_unknown_domain_id(): void
    {
        // Same lazy ViewDomain resolution as saveNameservers().
        $result = $this->module->getEppCode(['domain' => 'eppdomain.com', 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('/ViewDomain?', $this->http->requests[0]['url']);
    }

    public function test_sync_maps_registry_status_and_parses_expiry(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"Status":"Active","expirationDate":"2027-06-15"}}');

        $result = $this->module->sync(['domain' => 'syncme.com', 'registrar' => $this->registrar]);

        $this->assertTrue($result['success']);
        $this->assertSame('active', $result['status']);
        $this->assertSame('2027-06-15', $result['expiryDate']);
    }

    public function test_sync_reports_failure_when_domain_is_not_found(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Not Found","statusCode":404},"responseData":null}');

        $result = $this->module->sync(['domain' => 'missing.com', 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
    }

    public function test_unreachable_api_reports_failure_without_throwing(): void
    {
        $this->http->respondWith(0, '');

        $result = $this->module->renew(['domain' => 'unreachable.com', 'years' => 1, 'registrarClientId' => '1', 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
    }

    public function test_get_contact_info_fails_without_a_contact_id_or_an_email_to_resolve_one_from(): void
    {
        $result = $this->module->getContactInfo(['domain' => 'example.com', 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['contacts']);
        $this->assertCount(0, $this->http->requests, 'with nothing to search on there is nothing to ask the registrar');
    }

    /**
     * Same shape as the renew() defect: an imported domain has a real
     * registrant contact at ConnectReseller but no local
     * `registrar_contact_id`, and this reported "no contact on file".
     */
    public function test_get_contact_info_resolves_an_unlinked_contact_from_the_registrar(): void
    {
        $this->http->respondInSequence([
            // registrantsearchlist — clientId already known, so no ViewClient.
            ['status' => 200, 'body' => '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"records":[{"registrantContactId":321}]}}'],
            // ViewRegistrant
            ['status' => 200, 'body' => '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"Name":"Jane Doe"}}'],
        ]);

        $result = $this->module->getContactInfo([
            'domain' => 'imported.com',
            'registrar' => $this->registrar,
            'registrarClientId' => '777',
            'client' => ['email' => 'owner@example.test'],
        ]);

        $this->assertCount(2, $this->http->requests);
        $this->assertStringContainsString('/registrantsearchlist?', $this->http->requests[0]['url']);
        $this->assertStringContainsString('/ViewRegistrant?', $this->http->requests[1]['url']);
        $this->assertStringContainsString('RegistrantContactId=321', $this->http->requests[1]['url']);

        $this->assertTrue($result['success']);
        $this->assertSame('Jane Doe', $result['contacts']['Name']);
        $this->assertSame('321', $result['registrarContactId'], 'the resolved ID must come back so the domain row gets linked');
    }

    public function test_get_contact_info_never_creates_a_customer_while_reading(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Not found","statusCode":400},"responseData":null}');

        $this->module->getContactInfo([
            'domain' => 'imported.com',
            'registrar' => $this->registrar,
            'client' => ['email' => 'owner@example.test'],
        ]);

        $urls = array_column($this->http->requests, 'url');
        $this->assertSame([], array_values(array_filter($urls, static fn (string $u): bool => str_contains($u, '/AddClient?'))), 'a read must never create a customer record');
    }

    public function test_get_contact_info_hits_viewregistrant_when_a_contact_id_is_known(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"Name":"Jane Doe","emailaddress":"jane@example.test"}}');

        $result = $this->module->getContactInfo(['domain' => 'example.com', 'registrarContactId' => '321', 'registrar' => $this->registrar]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/ViewRegistrant?', $request['url']);
        $this->assertStringContainsString('RegistrantContactId=321', $request['url']);
        $this->assertTrue($result['success']);
        $this->assertSame('Jane Doe', $result['contacts']['Name']);
    }

    public function test_save_contact_info_modifies_an_existing_contact_when_the_id_is_known(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Updated","statusCode":200},"responseData":null}');

        $result = $this->module->saveContactInfo([
            'domain' => 'example.com',
            'registrarClientId' => '42',
            'registrarContactId' => '321',
            'contacts' => ['name' => 'Jane Doe', 'email' => 'jane@example.test'],
            'registrar' => $this->registrar,
        ]);

        $request = $this->http->lastRequest();
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/ModifyRegistrantContact?', $request['url']);
        $this->assertStringContainsString('RegistrantContactId=321', $request['url']);
        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('registrarContactId', $result);
    }

    public function test_save_contact_info_creates_a_new_contact_and_looks_up_its_id(): void
    {
        // Shared fixture answers both AddRegistrantContact (statusCode 200
        // is all it reads) and registrantsearchlist (needs
        // data.records[0].registrantContactId).
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"records":[{"registrantContactId":321}]}}');

        $result = $this->module->saveContactInfo([
            'domain' => 'example.com',
            'registrarClientId' => '42',
            'contacts' => ['name' => 'Jane Doe', 'email' => 'jane@example.test', 'country' => 'US'],
            'registrar' => $this->registrar,
        ]);

        $this->assertCount(2, $this->http->requests);
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/AddRegistrantContact?', $this->http->requests[0]['url']);
        $this->assertStringContainsString('Id=42', $this->http->requests[0]['url']);
        $this->assertStringStartsWith('https://api.connectreseller.com/ConnectReseller/ESHOP/registrantsearchlist?', $this->http->requests[1]['url']);
        $this->assertStringContainsString('searchQuery=jane%40example.test', $this->http->requests[1]['url']);

        $this->assertTrue($result['success']);
        $this->assertSame('321', $result['registrarContactId']);
    }

    public function test_save_contact_info_creates_a_connectreseller_customer_when_none_is_known_yet(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"clientId":777,"records":[{"registrantContactId":321}]}}');

        $result = $this->module->saveContactInfo([
            'domain' => 'example.com',
            'client' => ['email' => 'buyer@example.test', 'first_name' => 'Buyer', 'last_name' => 'Person'],
            'contacts' => ['name' => 'Jane Doe', 'email' => 'jane@example.test'],
            'registrar' => $this->registrar,
        ]);

        // ViewClient resolves the customer on the first call now, so no
        // AddClient is needed — see ensureCustomerId().
        $this->assertCount(3, $this->http->requests, 'ViewClient, AddRegistrantContact, registrantsearchlist');
        $this->assertTrue($result['success']);
        $this->assertSame('777', $result['registrarClientId']);
        $this->assertSame('321', $result['registrarContactId']);
    }

    /**
     * Regression coverage for the "domains keep reverting to pending" bug:
     * mapStatus() used to default any unrecognised Status value to 'pending',
     * and DomainSyncJob runs daily against every locally-active domain,
     * overwriting on any mismatch — so one unrecognised/empty response was
     * enough to silently downgrade a genuinely active domain, and it kept
     * recurring. sync() must now omit 'status' entirely rather than guess.
     */
    public function test_sync_maps_every_documented_status_value(): void
    {
        $cases = [
            'Active' => 'active',
            'Inactive' => 'pending',
            'Pending Delete Restorable' => 'grace',
            'Deleted' => 'cancelled',
            'Suspended' => 'cancelled',
        ];

        foreach ($cases as $registryStatus => $expectedLocalStatus) {
            $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"Status":"' . $registryStatus . '","expirationDate":"2028-01-01"}}');

            $result = $this->module->sync(['domain' => 'example.com', 'registrar' => $this->registrar]);

            $this->assertTrue($result['success']);
            $this->assertSame($expectedLocalStatus, $result['status'] ?? null, "registry status \"{$registryStatus}\"");
        }
    }

    public function test_sync_never_guesses_a_status_for_an_unrecognised_registry_value(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"Status":"SomeNewStatusConnectResellerAddedLater","expirationDate":"2028-01-01"}}');

        $result = $this->module->sync(['domain' => 'example.com', 'registrar' => $this->registrar]);

        $this->assertTrue($result['success'], 'the sync call itself still succeeded — only the status mapping is unconfident');
        $this->assertArrayNotHasKey('status', $result, 'an unrecognised status must never fall back to a guessed value that overwrites the domain\'s real local status');
        $this->assertSame('2028-01-01', $result['expiryDate'], 'expiry still syncs even when status is unrecognised');
    }

    public function test_sync_never_guesses_a_status_when_the_status_field_is_missing(): void
    {
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"expirationDate":"2028-01-01"}}');

        $result = $this->module->sync(['domain' => 'example.com', 'registrar' => $this->registrar]);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('status', $result, 'a missing Status field (momentary API hiccup) must not be treated as "pending"');
    }
}
