<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\AutoChargeService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\PaymentGatewayRepository;
use CodeVault\Billing\PaymentMethodRepository;
use CodeVault\Billing\PaymentService;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\GatewayModule;
use CodeVault\Modules\ModuleManager;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class AutoChargeServiceTest extends DatabaseTestCase
{
    private AutoChargeService $autoCharge;
    private InvoiceRepository $invoices;
    private PaymentMethodRepository $methods;
    private object $gateway;
    private int $clientId;
    private array $client;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $clients = new ClientRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $transactions = new TransactionRepository($this->db);
        $this->methods = new PaymentMethodRepository($this->db);
        $gateways = new PaymentGatewayRepository($this->db);
        $payments = new PaymentService($this->invoices, $transactions, new HookDispatcher());

        // A stand-in gateway whose chargeToken outcome the test controls.
        $this->gateway = new class implements GatewayModule {
            public bool $shouldSucceed = true;
            public array $lastCharge = [];
            public function metadata(): array { return ['name' => 'Fake', 'description' => '', 'version' => '1', 'author' => 'test']; }
            public function configOptions(): array { return []; }
            public function isOffsite(): bool { return true; }
            public function capture(array $params): array { return ['success' => false, 'message' => 'n/a']; }
            public function refund(array $params): array { return ['success' => true, 'message' => 'ok']; }
            public function void(array $params): array { return ['success' => false, 'message' => 'n/a']; }
            public function tokenize(array $params): array { return ['success' => false, 'message' => 'n/a']; }
            public function chargeToken(array $params): array
            {
                $this->lastCharge = $params;
                return $this->shouldSucceed
                    ? ['success' => true, 'transactionId' => 'fake-txn-1', 'status' => 'success', 'message' => 'ok']
                    : ['success' => false, 'status' => 'failed', 'message' => 'card declined'];
            }
            public function handleCallback(array $rawPayload, array $headers): array { return ['valid' => true, 'event' => 'x', 'data' => []]; }
        };

        $modules = new ModuleManager(new HookDispatcher());
        $modules->register(GatewayModule::class, 'paystack', $this->gateway);

        // Enable the seeded paystack row with a USD gateway currency so no
        // cross-currency conversion is needed for this test.
        $this->db->update(
            'UPDATE payment_gateways SET config = ?, is_enabled = 1 WHERE slug = ?',
            [json_encode(['secret_key' => 'sk_test', 'gateway_currency' => 'USD']), 'paystack']
        );

        $this->autoCharge = new AutoChargeService($this->methods, $gateways, $transactions, $payments, $modules, $this->db);

        $this->clientId = $clients->create([
            'email' => 'autopay@example.test',
            'password' => 'secret123',
            'first_name' => 'Auto',
            'last_name' => 'Pay',
        ]);
        $this->client = $clients->find($this->clientId);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'unpaid', 40.00, 0.0, 40.00, null, 1.0000, substr($now, 0, 10), $now, $now]
        );
    }

    private function invoice(): array
    {
        return $this->invoices->find($this->invoiceId);
    }

    public function test_charges_saved_method_and_marks_invoice_paid(): void
    {
        $this->methods->store($this->clientId, 'paystack', 'AUTH_saved', ['brand' => 'visa', 'last4' => '4081']);

        $result = $this->autoCharge->attempt($this->invoice(), $this->client);

        $this->assertTrue($result['charged']);
        $this->assertSame('paid', $this->invoice()['status']);
        // The saved token was the one charged, in the invoice's own currency.
        $this->assertSame('AUTH_saved', $this->gateway->lastCharge['token']);
        $this->assertSame(40.00, $this->gateway->lastCharge['amount']);
    }

    public function test_no_saved_method_leaves_invoice_unpaid(): void
    {
        $result = $this->autoCharge->attempt($this->invoice(), $this->client);

        $this->assertFalse($result['charged']);
        $this->assertSame('no-saved-method', $result['reason']);
        $this->assertSame('unpaid', $this->invoice()['status']);
    }

    public function test_declined_charge_leaves_invoice_unpaid_for_dunning(): void
    {
        $this->methods->store($this->clientId, 'paystack', 'AUTH_saved', ['brand' => 'visa', 'last4' => '4081']);
        $this->gateway->shouldSucceed = false;

        $result = $this->autoCharge->attempt($this->invoice(), $this->client);

        $this->assertFalse($result['charged']);
        $this->assertStringContainsString('declined', $result['reason']);
        $this->assertSame('unpaid', $this->invoice()['status']);
    }

    public function test_already_paid_invoice_is_not_charged_again(): void
    {
        $this->methods->store($this->clientId, 'paystack', 'AUTH_saved', ['brand' => 'visa', 'last4' => '4081']);
        $this->db->update('UPDATE invoices SET status = ? WHERE id = ?', ['paid', $this->invoiceId]);

        $result = $this->autoCharge->attempt($this->invoice(), $this->client);

        $this->assertFalse($result['charged']);
        $this->assertSame('not-unpaid', $result['reason']);
        $this->assertSame([], $this->gateway->lastCharge);
    }
}
