<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ViesVatLookupService;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ViesVatLookupService against a FakeHttpClient scripted with the
 * exact response shapes captured from the real, live EU VIES REST API
 * during development (see ViesVatLookupService's class docblock) — this
 * suite never calls the real service (that would make the suite flaky and
 * slow), but the fixture bodies below are not guessed.
 */
final class ViesVatLookupServiceTest extends TestCase
{
    public function test_valid_response_is_checked_and_valid_with_company_name(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'isValid' => true,
            'requestDate' => '2026-01-01T00:00:00.000Z',
            'userError' => 'VALID',
            'name' => 'GOOGLE IRELAND LIMITED',
            'address' => '3RD FLOOR, GORDON HOUSE, BARROW STREET, DUBLIN 4',
            'vatNumber' => '6388047V',
        ]));

        $result = (new ViesVatLookupService($http))->lookup('IE', '6388047V');

        $this->assertTrue($result['checked']);
        $this->assertTrue($result['valid']);
        $this->assertSame('GOOGLE IRELAND LIMITED', $result['name']);
        $this->assertNull($result['error']);
    }

    public function test_invalid_vat_number_is_checked_and_not_valid(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'isValid' => false,
            'userError' => 'INVALID',
            'name' => '---',
            'address' => '---',
            'vatNumber' => '00000000',
        ]));

        $result = (new ViesVatLookupService($http))->lookup('IE', '00000000');

        $this->assertTrue($result['checked']);
        $this->assertFalse($result['valid']);
    }

    public function test_placeholder_name_of_three_dashes_is_normalized_to_null(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'isValid' => true,
            'userError' => 'VALID',
            'name' => '---',
            'vatNumber' => '123',
        ]));

        $result = (new ViesVatLookupService($http))->lookup('DE', '123');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['name']);
    }

    public function test_invalid_input_error_is_inconclusive_not_a_rejection(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'isValid' => false,
            'userError' => 'INVALID_INPUT',
            'vatNumber' => '123456789',
        ]));

        $result = (new ViesVatLookupService($http))->lookup('ZZ', '123456789');

        $this->assertFalse($result['checked']);
        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['error']);
    }

    public function test_service_unavailable_is_inconclusive_not_a_rejection(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'isValid' => false,
            'userError' => 'MS_UNAVAILABLE',
        ]));

        $result = (new ViesVatLookupService($http))->lookup('DE', '123456789');

        $this->assertFalse($result['checked']);
        $this->assertFalse($result['valid']);
    }

    public function test_network_failure_fails_open_as_unchecked(): void
    {
        $http = new FakeHttpClient(0, '');

        $result = (new ViesVatLookupService($http))->lookup('DE', '123456789');

        $this->assertFalse($result['checked']);
        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['error']);
    }

    public function test_non_200_status_fails_open_as_unchecked(): void
    {
        $http = new FakeHttpClient(503, 'Service Unavailable');

        $result = (new ViesVatLookupService($http))->lookup('DE', '123456789');

        $this->assertFalse($result['checked']);
    }

    public function test_malformed_json_body_fails_open_as_unchecked(): void
    {
        $http = new FakeHttpClient(200, 'not json');

        $result = (new ViesVatLookupService($http))->lookup('DE', '123456789');

        $this->assertFalse($result['checked']);
    }

    public function test_greece_country_code_is_translated_to_vies_el_alias(): void
    {
        $http = new FakeHttpClient(200, json_encode(['isValid' => true, 'userError' => 'VALID', 'name' => 'Test']));

        (new ViesVatLookupService($http))->lookup('GR', '123456789');

        $this->assertStringContainsString('/ms/EL/vat/', (string) $http->lastRequest()['url']);
    }

    public function test_strips_country_prefix_from_the_vat_number_before_the_request(): void
    {
        $http = new FakeHttpClient(200, json_encode(['isValid' => true, 'userError' => 'VALID', 'name' => 'Test']));

        (new ViesVatLookupService($http))->lookup('DE', 'DE123456789');

        $this->assertStringContainsString('/vat/123456789', (string) $http->lastRequest()['url']);
    }
}
