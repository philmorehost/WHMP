<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Domains\ResellerClubRegistrarModule;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * ResellerClub's register.json/transfer.json carry four mandatory contact ids
 * (reg/admin/tech/billing-contact-id) — an order with empty ids is rejected
 * by the API. These tests pin the contact-resolution chain: honour an id the
 * caller already has, reuse the client's existing contact found by email, or
 * create one via contacts/add.json (whose documented response IS the new
 * contact id) — before the order is ever placed.
 */
final class ResellerClubRegistrarModuleTest extends TestCase
{
    private FakeHttpClient $http;
    private ResellerClubRegistrarModule $module;

    /** @var array<string, mixed> */
    private array $registrar = ['reseller_id' => '123', 'api_key' => 'key', 'customer_id' => '456'];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->module = new ResellerClubRegistrarModule($this->http);
    }

    /** @return array<string, mixed> */
    private function contacts(): array
    {
        return ['contacts' => ['name' => 'Buyer Person', 'email' => 'buyer@example.test', 'address1' => '1 Street', 'city' => 'Lagos', 'state' => 'Lagos', 'postcode' => '100001', 'country' => 'ng', 'phone' => '08012345678']];
    }

    public function test_register_sends_a_supplied_contact_id_without_any_contact_lookup(): void
    {
        // No status/message keys → LogicBoxes success per the module's decode.
        $this->http->respondWith(200, '{"entityid":123456}');

        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 1,
            'nameservers' => ['ns1.test', 'ns2.test'],
            'registrar' => $this->registrar,
            'contacts' => ['contact_id' => '555'],
        ]);

        $this->assertCount(1, $this->http->requests, 'a known contact id must not trigger search/add calls');

        $url = $this->http->requests[0]['url'];
        $this->assertStringContainsString('/domains/register.json?', $url);
        $this->assertStringContainsString('domain-name=newdomain.com', $url);
        $this->assertStringContainsString('customer-id=456', $url);
        $this->assertStringContainsString('reg-contact-id=555', $url);
        $this->assertStringContainsString('admin-contact-id=555', $url);
        $this->assertStringContainsString('tech-contact-id=555', $url);
        $this->assertStringContainsString('billing-contact-id=555', $url);
        $this->assertStringContainsString('invoice-option=NoInvoice', $url);

        $this->assertTrue($result['success']);
        $this->assertSame('555', $result['registrarContactId']);
    }

    public function test_register_reuses_the_clients_existing_contact_found_by_email(): void
    {
        $this->http->respondInSequence([
            // contacts/search.json — the client already has a contact there.
            ['status' => 200, 'body' => '{"1":{"entity.entityid":777,"contact.emailaddr":"buyer@example.test"},"recsonpage":1}'],
            // domains/register.json.
            ['status' => 200, 'body' => '{"entityid":123456}'],
        ]);

        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 1,
            'nameservers' => [],
            'registrar' => $this->registrar,
        ] + $this->contacts());

        $this->assertCount(2, $this->http->requests);

        $search = $this->http->requests[0]['url'];
        $this->assertStringContainsString('/contacts/search.json?', $search);
        $this->assertStringContainsString('customer-id=456', $search);
        $this->assertStringContainsString('email=buyer%40example.test', $search);
        $this->assertStringContainsString('type=Contact', $search);

        $register = $this->http->requests[1]['url'];
        $this->assertStringContainsString('reg-contact-id=777', $register);

        $this->assertTrue($result['success']);
        $this->assertSame('777', $result['registrarContactId'], 'the found contact id is reported back so the domain row can be linked');
    }

    public function test_register_creates_a_contact_when_the_search_comes_up_empty(): void
    {
        $this->http->respondInSequence([
            // contacts/search.json — nothing for this email yet.
            ['status' => 200, 'body' => '{"recsonpage":0}'],
            // contacts/add.json — its documented response IS the new id.
            ['status' => 200, 'body' => '888888'],
            // domains/register.json.
            ['status' => 200, 'body' => '{"entityid":123456}'],
        ]);

        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 1,
            'nameservers' => [],
            'registrar' => $this->registrar,
        ] + $this->contacts());

        $this->assertCount(3, $this->http->requests);

        $add = $this->http->requests[1]['url'];
        $this->assertStringContainsString('/contacts/add.json?', $add);
        $this->assertStringContainsString('customer-id=456', $add);
        $this->assertStringContainsString('type=Contact', $add);
        $this->assertStringContainsString('name=Buyer+Person', $add);
        $this->assertStringContainsString('email=buyer%40example.test', $add);
        $this->assertStringContainsString('address-line-1=1+Street', $add);
        $this->assertStringContainsString('city=Lagos', $add);
        $this->assertStringContainsString('state=Lagos', $add);
        $this->assertStringContainsString('country=NG', $add);
        $this->assertStringContainsString('zipcode=100001', $add);
        $this->assertStringContainsString('phone=08012345678', $add);

        $register = $this->http->requests[2]['url'];
        $this->assertStringContainsString('reg-contact-id=888888', $register);

        $this->assertTrue($result['success']);
        $this->assertSame('888888', $result['registrarContactId']);
    }

    public function test_register_fails_before_placing_the_order_when_contact_creation_fails(): void
    {
        $this->http->respondInSequence([
            ['status' => 200, 'body' => '{"recsonpage":0}'],
            ['status' => 200, 'body' => '{"status":"ERROR","message":"Invalid phone number"}'],
        ]);

        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 1,
            'nameservers' => [],
            'registrar' => $this->registrar,
        ] + $this->contacts());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Could not assign a registrant contact', $result['message']);
        $this->assertStringContainsString('Invalid phone number', $result['message']);

        $urls = array_column($this->http->requests, 'url');
        $this->assertSame([], array_values(array_filter($urls, static fn (string $u): bool => str_contains($u, '/domains/register.json'))), 'the order must not be placed with an unassigned registrant');
    }

    public function test_register_fails_clearly_when_the_default_customer_id_is_missing(): void
    {
        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 1,
            'registrar' => ['reseller_id' => '123', 'api_key' => 'key'],
        ] + $this->contacts());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Default Customer ID', $result['message']);
        $this->assertCount(0, $this->http->requests);
    }

    public function test_register_fails_clearly_when_there_are_no_contact_details_at_all(): void
    {
        $result = $this->module->register([
            'domain' => 'newdomain.com',
            'years' => 1,
            'registrar' => $this->registrar,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Could not assign a registrant contact', $result['message']);
        $this->assertCount(0, $this->http->requests);
    }

    public function test_transfer_carries_the_same_registrant_contact_on_all_four_roles(): void
    {
        $this->http->respondWith(200, '{"status":"Success"}');

        $result = $this->module->transfer([
            'domain' => 'movemydomain.com',
            'eppCode' => 'secret',
            'registrar' => $this->registrar,
            'registrarContactId' => '777',
        ]);

        $url = $this->http->requests[0]['url'];
        $this->assertStringContainsString('/domains/transfer.json?', $url);
        $this->assertStringContainsString('auth-code=secret', $url);
        $this->assertStringContainsString('reg-contact-id=777', $url);
        $this->assertStringContainsString('admin-contact-id=777', $url);
        $this->assertStringContainsString('tech-contact-id=777', $url);
        $this->assertStringContainsString('billing-contact-id=777', $url);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('registrarContactId', $result, 'an id the caller supplied is not reported back as newly learned');
    }
}
