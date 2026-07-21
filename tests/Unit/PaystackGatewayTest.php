<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\PaystackGateway;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class PaystackGatewayTest extends TestCase
{
    public function test_capture_builds_the_correct_initialize_request(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'abc123', 'reference' => 'cv-paystack-5-aabbcc'],
        ]));
        $gateway = new PaystackGateway($http);

        $result = $gateway->capture([
            'config' => ['secret_key' => 'sk_test_123'],
            'email' => 'client@example.test',
            'amount' => 25.50,
            'reference' => 'cv-paystack-5-aabbcc',
            'callbackUrl' => 'https://example.test/pay/paystack/callback',
            'metadata' => ['invoice_id' => 5],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('https://checkout.paystack.com/abc123', $result['redirectUrl']);

        $sent = $http->lastRequest();
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('https://api.paystack.co/transaction/initialize', $sent['url']);
        $this->assertSame('Bearer sk_test_123', $sent['headers']['Authorization']);

        $body = json_decode((string) $sent['body'], true);
        $this->assertSame('client@example.test', $body['email']);
        // Amount must be in kobo (smallest unit) — 25.50 NGN => 2550 kobo.
        $this->assertSame(2550, $body['amount']);
        $this->assertSame('cv-paystack-5-aabbcc', $body['reference']);
        $this->assertSame(5, $body['metadata']['invoice_id']);
    }

    public function test_capture_fails_cleanly_without_a_configured_secret_key(): void
    {
        $gateway = new PaystackGateway(new FakeHttpClient());

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

    public function test_capture_reports_failure_when_paystack_rejects_the_request(): void
    {
        $http = new FakeHttpClient(400, json_encode(['status' => false, 'message' => 'Invalid key']));
        $gateway = new PaystackGateway($http);

        $result = $gateway->capture([
            'config' => ['secret_key' => 'sk_bad'],
            'email' => 'a@example.test',
            'amount' => 10.0,
            'reference' => 'ref',
            'callbackUrl' => 'https://example.test/callback',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid key', $result['message']);
    }

    public function test_verify_transaction_reports_success_for_a_successful_charge(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'status' => true,
            'data' => ['status' => 'success', 'reference' => 'cv-paystack-5-xyz', 'amount' => 2550, 'metadata' => ['invoice_id' => 5]],
        ]));
        $gateway = new PaystackGateway($http);

        $result = $gateway->verifyTransaction('cv-paystack-5-xyz', ['secret_key' => 'sk_test_123']);

        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['status']);
        $this->assertSame(25.50, $result['amount']);
        $this->assertSame(5, $result['metadata']['invoice_id']);

        $sent = $http->lastRequest();
        $this->assertSame('GET', $sent['method']);
        $this->assertStringContainsString('/transaction/verify/cv-paystack-5-xyz', $sent['url']);
    }

    public function test_verify_transaction_reports_failure_for_an_abandoned_charge(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'status' => true,
            'data' => ['status' => 'abandoned', 'reference' => 'ref', 'amount' => 1000, 'metadata' => []],
        ]));
        $gateway = new PaystackGateway($http);

        $result = $gateway->verifyTransaction('ref', ['secret_key' => 'sk_test_123']);

        $this->assertFalse($result['success']);
        $this->assertSame('abandoned', $result['status']);
    }

    public function test_verify_transaction_handles_a_network_or_api_error_gracefully(): void
    {
        $http = new FakeHttpClient(0, '');
        $gateway = new PaystackGateway($http);

        $result = $gateway->verifyTransaction('ref', ['secret_key' => 'sk_test_123']);

        $this->assertFalse($result['success']);
        $this->assertSame('error', $result['status']);
    }

    public function test_verify_signature_accepts_a_correctly_signed_payload(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"ref"}}';
        $secret = 'sk_test_123';
        $validSignature = hash_hmac('sha512', $body, $secret);

        $this->assertTrue(PaystackGateway::verifySignature($body, $validSignature, $secret));
    }

    public function test_verify_signature_rejects_a_tampered_payload(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"ref"}}';
        $secret = 'sk_test_123';
        $validSignature = hash_hmac('sha512', $body, $secret);

        $tamperedBody = '{"event":"charge.success","data":{"reference":"ref-tampered"}}';

        $this->assertFalse(PaystackGateway::verifySignature($tamperedBody, $validSignature, $secret));
    }

    public function test_verify_signature_rejects_a_signature_made_with_the_wrong_secret(): void
    {
        $body = '{"event":"charge.success"}';
        $signature = hash_hmac('sha512', $body, 'wrong-secret');

        $this->assertFalse(PaystackGateway::verifySignature($body, $signature, 'sk_test_123'));
    }

    public function test_refund_sends_the_transaction_reference(): void
    {
        $http = new FakeHttpClient(200, json_encode(['status' => true, 'message' => 'Refund queued']));
        $gateway = new PaystackGateway($http);

        $result = $gateway->refund(['config' => ['secret_key' => 'sk_test_123'], 'transactionId' => 'ref-123']);

        $this->assertTrue($result['success']);
        $body = json_decode((string) $http->lastRequest()['body'], true);
        $this->assertSame('ref-123', $body['transaction']);
    }

    public function test_is_offsite(): void
    {
        $this->assertTrue((new PaystackGateway(new FakeHttpClient()))->isOffsite());
    }

    public function test_void_and_tokenize_report_unsupported_rather_than_erroring(): void
    {
        $gateway = new PaystackGateway(new FakeHttpClient());

        $this->assertFalse($gateway->void([])['success']);
        $this->assertFalse($gateway->tokenize([])['success']);
        $this->assertFalse($gateway->chargeToken([])['success']);
    }

    public function test_verify_transaction_surfaces_a_reusable_authorization_for_saving(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'status' => true,
            'data' => [
                'status' => 'success',
                'reference' => 'ref',
                'amount' => 2550,
                'metadata' => ['client_id' => 7],
                'authorization' => [
                    'authorization_code' => 'AUTH_abc123',
                    'reusable' => true,
                    'card_type' => 'visa',
                    'last4' => '4081',
                    'exp_month' => '12',
                    'exp_year' => '2030',
                ],
            ],
        ]));

        $result = (new PaystackGateway($http))->verifyTransaction('ref', ['secret_key' => 'sk_test_123']);

        $this->assertNotNull($result['authorization']);
        $this->assertSame('AUTH_abc123', $result['authorization']['token']);
        $this->assertSame('visa', $result['authorization']['brand']);
        $this->assertSame('4081', $result['authorization']['last4']);
    }

    public function test_verify_transaction_omits_a_non_reusable_authorization(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'status' => true,
            'data' => [
                'status' => 'success',
                'reference' => 'ref',
                'amount' => 2550,
                'metadata' => [],
                'authorization' => ['authorization_code' => 'AUTH_x', 'reusable' => false],
            ],
        ]));

        $result = (new PaystackGateway($http))->verifyTransaction('ref', ['secret_key' => 'sk_test_123']);

        $this->assertNull($result['authorization']);
    }

    public function test_charge_token_charges_a_saved_authorization(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'status' => true,
            'data' => ['status' => 'success', 'reference' => 'cv-auto-paystack-9-abc'],
        ]));
        $gateway = new PaystackGateway($http);

        $result = $gateway->chargeToken([
            'config' => ['secret_key' => 'sk_test_123'],
            'token' => 'AUTH_abc123',
            'email' => 'client@example.test',
            'amount' => 40.00,
            'reference' => 'cv-auto-paystack-9-abc',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('cv-auto-paystack-9-abc', $result['transactionId']);

        $sent = $http->lastRequest();
        $this->assertSame('https://api.paystack.co/transaction/charge_authorization', $sent['url']);
        $body = json_decode((string) $sent['body'], true);
        $this->assertSame('AUTH_abc123', $body['authorization_code']);
        $this->assertSame(4000, $body['amount']); // 40.00 => 4000 kobo
    }

    public function test_charge_token_reports_failure_on_a_declined_card(): void
    {
        $http = new FakeHttpClient(200, json_encode([
            'status' => true,
            'data' => ['status' => 'failed', 'reference' => 'ref'],
        ]));

        $result = (new PaystackGateway($http))->chargeToken([
            'config' => ['secret_key' => 'sk_test_123'],
            'token' => 'AUTH_abc123',
            'email' => 'client@example.test',
            'amount' => 40.00,
            'reference' => 'ref',
        ]);

        $this->assertFalse($result['success']);
    }
}
