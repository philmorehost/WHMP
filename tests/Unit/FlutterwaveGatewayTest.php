<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\FlutterwaveGateway;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class FlutterwaveGatewayTest extends TestCase
{
    public function test_capture_builds_the_correct_payments_request(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'status' => 'success',
            'data' => ['link' => 'https://checkout.flutterwave.com/pay/abc123'],
        ]));
        $gateway = new FlutterwaveGateway($http);

        $result = $gateway->capture([
            'config' => ['secret_key' => 'FLWSECK_TEST-123'],
            'email' => 'client@example.test',
            'amount' => 25.50,
            'currency' => 'NGN',
            'reference' => 'cv-flutterwave-5-aabbcc',
            'callbackUrl' => 'https://example.test/pay/flutterwave/callback',
            'metadata' => ['invoice_id' => 5],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://checkout.flutterwave.com/pay/abc123', $result['redirectUrl']);

        $sent = $http->lastRequest();
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('https://api.flutterwave.com/v3/payments', $sent['url']);
        $this->assertSame('Bearer FLWSECK_TEST-123', $sent['headers']['Authorization']);

        $body = json_decode((string) $sent['body'], true);
        $this->assertSame('cv-flutterwave-5-aabbcc', $body['tx_ref']);
        // Unlike Paystack, Flutterwave takes the amount in the currency's major unit, not kobo.
        $this->assertSame(25.50, $body['amount']);
        $this->assertSame('client@example.test', $body['customer']['email']);
        $this->assertSame(5, $body['meta']['invoice_id']);
    }

    public function test_capture_fails_cleanly_without_a_configured_secret_key(): void
    {
        $gateway = new FlutterwaveGateway(new FakeHttpClient());

        $result = $gateway->capture([
            'config' => [],
            'email' => 'client@example.test',
            'amount' => 10.0,
            'reference' => 'ref',
            'callbackUrl' => 'https://example.test/callback',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['message']);
    }

    public function test_verify_transaction_reports_success_for_a_successful_charge(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'status' => 'success',
            'data' => ['status' => 'successful', 'tx_ref' => 'cv-flutterwave-5-xyz', 'amount' => 25.50, 'meta' => ['invoice_id' => 5]],
        ]));
        $gateway = new FlutterwaveGateway($http);

        $result = $gateway->verifyTransaction('998877', ['secret_key' => 'FLWSECK_TEST-123']);

        $this->assertTrue($result['success']);
        $this->assertSame('successful', $result['status']);
        $this->assertSame(25.50, $result['amount']);
        $this->assertSame(5, $result['metadata']['invoice_id']);

        $sent = $http->lastRequest();
        $this->assertStringContainsString('/transactions/998877/verify', $sent['url']);
    }

    public function test_verify_transaction_reports_failure_for_a_failed_charge(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'status' => 'success',
            'data' => ['status' => 'failed', 'tx_ref' => 'ref', 'amount' => 1000, 'meta' => []],
        ]));
        $gateway = new FlutterwaveGateway($http);

        $result = $gateway->verifyTransaction('998877', ['secret_key' => 'FLWSECK_TEST-123']);

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
    }

    public function test_verify_signature_accepts_a_matching_hash(): void
    {
        $this->assertTrue(FlutterwaveGateway::verifySignature('my-secret-hash', 'my-secret-hash'));
    }

    public function test_verify_signature_rejects_a_mismatched_hash(): void
    {
        $this->assertFalse(FlutterwaveGateway::verifySignature('wrong-hash', 'my-secret-hash'));
    }

    public function test_verify_signature_rejects_when_no_secret_hash_is_configured(): void
    {
        $this->assertFalse(FlutterwaveGateway::verifySignature('anything', ''));
    }

    public function test_is_offsite(): void
    {
        $this->assertTrue((new FlutterwaveGateway(new FakeHttpClient()))->isOffsite());
    }

    public function test_void_and_tokenize_report_unsupported_rather_than_erroring(): void
    {
        $gateway = new FlutterwaveGateway(new FakeHttpClient());

        $this->assertFalse($gateway->void([])['success']);
        $this->assertFalse($gateway->tokenize([])['success']);
        $this->assertFalse($gateway->chargeToken([])['success']);
    }
}
