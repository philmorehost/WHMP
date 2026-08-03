<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\PaymentCallbackController;
use CodeVault\Billing\PaymentGatewayRepository;
use CodeVault\Billing\PaymentService;
use CodeVault\Billing\PaystackGateway;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\GatewayModule;
use CodeVault\Modules\ModuleManager;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class PaymentCallbackControllerTest extends DatabaseTestCase
{
    private PaymentCallbackController $controller;
    private InvoiceRepository $invoices;
    private TransactionRepository $transactions;
    private PaymentGatewayRepository $gateways;
    private FakeHttpClient $http;
    private ClientAuthGuard $guard;
    private int $clientId;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $clients = new ClientRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->transactions = new TransactionRepository($this->db);
        $this->gateways = new PaymentGatewayRepository($this->db);

        $emptyConfigDir = sys_get_temp_dir() . '/codevault-pay-test-' . uniqid();
        mkdir($emptyConfigDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($emptyConfigDir));
        $this->guard = new ClientAuthGuard($session, $clients);

        $this->http = new FakeHttpClient();
        $paystack = new PaystackGateway($this->http);
        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(GatewayModule::class, 'paystack', $paystack);

        $payments = new PaymentService($this->invoices, $this->transactions, new HookDispatcher());
        $config = new Config(sys_get_temp_dir() . '/codevault-pay-test-noenv-' . uniqid());

        $this->controller = new PaymentCallbackController(
            $this->guard,
            $this->invoices,
            $this->gateways,
            $this->transactions,
            $payments,
            $modules,
            $config,
            $this->db,
            new \CodeVault\Billing\PaymentMethodRepository($this->db),
            new CurrencyService(new CurrencyRepository($this->db))
        );

        $this->clientId = $clients->create([
            'email' => 'payer@example.test',
            'password' => 'secret123',
            'first_name' => 'Payer',
            'last_name' => 'Client',
        ]);
        $this->guard->login($clients->find($this->clientId));

        $currencies = new CurrencyRepository($this->db);
        $currencyLock = (new CurrencyService($currencies))->lockedColumnsFor(null);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'unpaid', 25.50, 0.0, 25.50, $currencyLock['currency_id'], $currencyLock['currency_rate'], substr($now, 0, 10), $now, $now]
        );

        // Migration 0079 already seeds the paystack row (disabled, no
        // config) — configure and enable it rather than inserting a
        // second row with the same unique slug.
        $this->db->update(
            'UPDATE payment_gateways SET config = ?, is_enabled = 1 WHERE slug = ?',
            [json_encode(['secret_key' => 'sk_test_123', 'public_key' => 'pk_test_123']), 'paystack']
        );
    }

    public function test_initiate_redirects_to_the_gateways_checkout_url_on_success(): void
    {
        $this->http->respondWith(200, json_encode([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz', 'reference' => 'r'],
        ]));

        $response = $this->controller->initiate($this->request(), ['id' => (string) $this->invoiceId, 'gateway' => 'paystack']);

        $this->assertSame(302, $response->status());

        $sent = $this->http->lastRequest();
        $body = json_decode((string) $sent['body'], true);
        $this->assertSame('payer@example.test', $body['email']);
        $this->assertSame(2550, $body['amount']);
        $this->assertSame($this->invoiceId, $body['metadata']['invoice_id']);
    }

    public function test_initiate_redirects_without_charging_when_the_invoice_is_already_fully_paid(): void
    {
        $this->db->update('UPDATE invoices SET status = ? WHERE id = ?', ['paid', $this->invoiceId]);
        $this->transactions->create($this->invoiceId, 'manual', 25.50);

        $this->controller->initiate($this->request(), ['id' => (string) $this->invoiceId, 'gateway' => 'paystack']);

        $this->assertCount(0, $this->http->requests, 'a fully-paid invoice must never trigger a new charge attempt');
    }

    public function test_initiate_redirects_without_charging_for_a_disabled_gateway(): void
    {
        $this->gateways->setEnabled('paystack', false);

        $response = $this->controller->initiate($this->request(), ['id' => (string) $this->invoiceId, 'gateway' => 'paystack']);

        $this->assertSame(302, $response->status());
        $this->assertCount(0, $this->http->requests);
    }

    public function test_callback_records_payment_and_marks_invoice_paid_on_a_verified_success(): void
    {
        $this->http->respondWith(200, json_encode([
            'status' => true,
            'data' => ['status' => 'success', 'reference' => 'cv-paystack-ref-1', 'amount' => 2550, 'metadata' => ['invoice_id' => $this->invoiceId]],
        ]));

        $request = new Request(['reference' => 'cv-paystack-ref-1'], [], ['REQUEST_METHOD' => 'GET'], []);
        $response = $this->controller->callback($request, ['gateway' => 'paystack']);

        $this->assertSame(302, $response->status());

        $invoice = $this->invoices->find($this->invoiceId);
        $this->assertSame('paid', $invoice['status']);

        $transaction = $this->transactions->findByGatewayTransactionId('paystack', 'cv-paystack-ref-1');
        $this->assertNotNull($transaction);
        $this->assertEqualsWithDelta(25.50, (float) $transaction['amount'], 0.001);
    }

    public function test_callback_does_not_mark_paid_when_verification_reports_failure(): void
    {
        $this->http->respondWith(200, json_encode([
            'status' => true,
            'data' => ['status' => 'failed', 'reference' => 'cv-paystack-ref-2', 'amount' => 2550, 'metadata' => ['invoice_id' => $this->invoiceId]],
        ]));

        $request = new Request(['reference' => 'cv-paystack-ref-2'], [], ['REQUEST_METHOD' => 'GET'], []);
        $this->controller->callback($request, ['gateway' => 'paystack']);

        $invoice = $this->invoices->find($this->invoiceId);
        $this->assertSame('unpaid', $invoice['status']);
        $this->assertNull($this->transactions->findByGatewayTransactionId('paystack', 'cv-paystack-ref-2'));
    }

    public function test_webhook_rejects_a_payload_with_an_invalid_signature(): void
    {
        $rawBody = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'cv-paystack-ref-3']]);
        $request = new Request([], json_decode($rawBody, true), ['REQUEST_METHOD' => 'POST'], ['X-PAYSTACK-SIGNATURE' => 'not-the-real-signature'], [], $rawBody);

        $response = $this->controller->webhook($request, ['gateway' => 'paystack']);

        $this->assertSame(401, $response->status());
        $this->assertNull($this->transactions->findByGatewayTransactionId('paystack', 'cv-paystack-ref-3'));
    }

    public function test_webhook_accepts_a_correctly_signed_payload_and_records_payment(): void
    {
        $rawBody = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'cv-paystack-ref-4']]);
        $validSignature = hash_hmac('sha512', $rawBody, 'sk_test_123');

        $this->http->respondWith(200, json_encode([
            'status' => true,
            'data' => ['status' => 'success', 'reference' => 'cv-paystack-ref-4', 'amount' => 2550, 'metadata' => ['invoice_id' => $this->invoiceId]],
        ]));

        $request = new Request([], json_decode($rawBody, true), ['REQUEST_METHOD' => 'POST'], ['X-PAYSTACK-SIGNATURE' => $validSignature], [], $rawBody);
        $response = $this->controller->webhook($request, ['gateway' => 'paystack']);

        $this->assertSame(200, $response->status());

        $invoice = $this->invoices->find($this->invoiceId);
        $this->assertSame('paid', $invoice['status']);
    }

    public function test_webhook_is_idempotent_against_a_duplicate_delivery(): void
    {
        $rawBody = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'cv-paystack-ref-5']]);
        $validSignature = hash_hmac('sha512', $rawBody, 'sk_test_123');

        $this->http->respondWith(200, json_encode([
            'status' => true,
            'data' => ['status' => 'success', 'reference' => 'cv-paystack-ref-5', 'amount' => 2550, 'metadata' => ['invoice_id' => $this->invoiceId]],
        ]));

        $request = new Request([], json_decode($rawBody, true), ['REQUEST_METHOD' => 'POST'], ['X-PAYSTACK-SIGNATURE' => $validSignature], [], $rawBody);

        // Paystack (and most providers) can and do redeliver the same
        // webhook — a second delivery must not double-credit the invoice.
        $this->controller->webhook($request, ['gateway' => 'paystack']);
        $this->controller->webhook($request, ['gateway' => 'paystack']);

        $allTransactions = $this->transactions->forInvoice($this->invoiceId);
        $this->assertCount(1, $allTransactions, 'a duplicate webhook delivery must not create a second transaction');
    }

    /**
     * The reported production defect: on an install whose base currency is
     * NGN, a ₦7,501.50 invoice was initialised at Paystack as ₦11,177,235 —
     * the invoice total multiplied by NGN's own leftover 1490 exchange rate.
     * The gateway must be asked for exactly the figure the invoice shows.
     */
    public function test_charge_amount_is_not_inflated_by_the_base_currencys_own_exchange_rate(): void
    {
        $currencies = new CurrencyRepository($this->db);
        $ngnId = $currencies->create('NGN', '₦', 1490.0000);
        $currencies->setDefault($ngnId);

        // Reproduce the legacy row the repository guards now prevent: base
        // currency still carrying the rate it had before being promoted.
        $this->db->update('UPDATE currencies SET exchange_rate = 1490 WHERE id = ?', [$ngnId]);

        $this->db->update('UPDATE invoices SET total = ?, subtotal = ?, currency_id = NULL, currency_rate = 1 WHERE id = ?', [7501.50, 7501.50, $this->invoiceId]);
        $this->db->update(
            'UPDATE payment_gateways SET config = ? WHERE slug = ?',
            [json_encode(['secret_key' => 'sk_test_123', 'gateway_currency' => 'NGN']), 'paystack']
        );

        $this->http->respondWith(200, json_encode([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz', 'reference' => 'r'],
        ]));

        $this->controller->initiate($this->request(), ['id' => (string) $this->invoiceId, 'gateway' => 'paystack']);

        $body = json_decode((string) $this->http->lastRequest()['body'], true);
        $this->assertSame(750150, $body['amount'], 'Paystack takes kobo: ₦7,501.50 is 750150, not 1117723500');
    }

    private function request(): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => 'POST'], []);
    }
}
