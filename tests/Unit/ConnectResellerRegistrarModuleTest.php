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

    public function test_register_creates_a_connectreseller_customer_when_none_is_known_yet(): void
    {
        // One shared fixture answers AddClient (needs statusCode 200),
        // ViewClient (needs data.clientId), domainorder, and ViewDomain —
        // FakeHttpClient returns the same canned response to every call.
        $this->http->respondWith(200, '{"responseMsg":{"message":"Success","statusCode":200},"responseData":{"clientId":777,"creationDate":"2026-01-01","expiryDate":"2028-01-01","domainNameId":555}}');

        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 1,
            'client' => ['email' => 'buyer@example.test', 'first_name' => 'Buyer', 'last_name' => 'Person'],
            'registrar' => $this->registrar,
        ]);

        $this->assertCount(4, $this->http->requests, 'AddClient, ViewClient, domainorder, ViewDomain');
        $this->assertStringContainsString('/AddClient?', $this->http->requests[0]['url']);
        $this->assertStringContainsString('UserName=buyer%40example.test', $this->http->requests[0]['url']);
        $this->assertStringContainsString('/ViewClient?', $this->http->requests[1]['url']);
        $this->assertStringContainsString('/domainorder?', $this->http->requests[2]['url']);
        $this->assertStringContainsString('Id=777', $this->http->requests[2]['url']);

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

    public function test_renew_fails_clearly_without_a_known_customer_id_and_makes_no_http_call(): void
    {
        $result = $this->module->renew(['domain' => 'renewme.com', 'years' => 1, 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('never registered/transferred', $result['message']);
        $this->assertCount(0, $this->http->requests);
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

    public function test_save_nameservers_fails_without_a_registrar_domain_id_and_makes_no_http_call(): void
    {
        $result = $this->module->saveNameservers(['domain' => 'nsdomain.com', 'nameservers' => ['ns1.test'], 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertCount(0, $this->http->requests);
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

    public function test_get_epp_code_fails_without_a_registrar_domain_id(): void
    {
        $result = $this->module->getEppCode(['domain' => 'eppdomain.com', 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertCount(0, $this->http->requests);
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

    public function test_get_contact_info_fails_without_a_registrar_contact_id_and_makes_no_http_call(): void
    {
        $result = $this->module->getContactInfo(['domain' => 'example.com', 'registrar' => $this->registrar]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['contacts']);
        $this->assertCount(0, $this->http->requests);
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

        $this->assertCount(4, $this->http->requests, 'AddClient, ViewClient, AddRegistrantContact, registrantsearchlist');
        $this->assertTrue($result['success']);
        $this->assertSame('777', $result['registrarClientId']);
        $this->assertSame('321', $result['registrarContactId']);
    }
}
