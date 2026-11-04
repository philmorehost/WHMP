<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Domains\NamecheapRegistrarModule;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Namecheap answers in XML (<ApiResponse Status="OK|ERROR">). These tests
 * pin the renewal contract: the new expiry is FETCHED from the renew
 * answer's DomainRenewResult@ExpirationDate rather than computed locally,
 * and a date that has not advanced past the expiry the renewal was made
 * against is ignored (registry not yet propagated) so the caller's
 * current-expiry + years fallback applies.
 */
final class NamecheapRegistrarModuleTest extends TestCase
{
    private FakeHttpClient $http;
    private NamecheapRegistrarModule $module;

    /** @var array<string, mixed> */
    private array $registrar = ['api_user' => 'u', 'api_key' => 'k', 'username' => 'u', 'client_ip' => '127.0.0.1'];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->module = new NamecheapRegistrarModule($this->http);
    }

    public function test_renew_hits_the_renew_command_and_fetches_the_new_expiry(): void
    {
        $this->http->respondWith(200, $this->okXml('11/06/2027'));

        $result = $this->module->renew(['domain' => 'renewme.com', 'years' => 1, 'registrar' => $this->registrar, 'currentExpiry' => '2026-11-06']);

        $request = $this->http->lastRequest();
        $this->assertStringContainsString('Command=namecheap.domains.renew', $request['url']);
        $this->assertStringContainsString('DomainName=renewme.com', $request['url']);
        $this->assertTrue($result['success']);
        $this->assertSame('2027-11-06', $result['expiryDate']);
    }

    public function test_renew_ignores_an_expiration_date_that_has_not_advanced(): void
    {
        $this->http->respondWith(200, $this->okXml('11/06/2026'));

        $result = $this->module->renew(['domain' => 'renewme.com', 'years' => 1, 'registrar' => $this->registrar, 'currentExpiry' => '2026-11-06']);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('expiryDate', $result);
    }

    public function test_error_xml_reports_the_registrars_error_text(): void
    {
        $this->http->respondWith(200, '<?xml version="1.0" encoding="utf-8"?><ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="2011280">Domain name not found</Error></Errors><Warnings/><RequestedCommand>namecheap.domains.renew</RequestedCommand><CommandResponse/></ApiResponse>');

        $result = $this->module->renew(['domain' => 'ghost.com', 'years' => 1, 'registrar' => $this->registrar, 'currentExpiry' => '2026-11-06']);

        $this->assertFalse($result['success']);
        $this->assertSame('Domain name not found', $result['message']);
    }

    private function okXml(string $expirationDate): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors/><Warnings/><RequestedCommand>namecheap.domains.renew</RequestedCommand><CommandResponse><DomainRenewResult Domain="renewme.com" Renewed="true" OrderID="1" TransactionID="2" ExpirationDate="' . $expirationDate . '"/></CommandResponse></ApiResponse>';
    }
}
