<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ClientCreditRepository;
use CodeVault\Billing\CreditService;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\PaymentService;
use CodeVault\Billing\ProrationMode;
use CodeVault\Billing\ProrationService;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class ProrationServiceTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private ProrationService $proration;
    private ClientCreditRepository $credit;
    private int $clientId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->services = new ServiceRepository($this->db);
        $clients = new ClientRepository($this->db);
        $tax = new TaxCalculator(new TaxRuleRepository($this->db), new VatNumberValidator(), new TaxSettings(new SettingsRepository($this->db)));
        $this->credit = new ClientCreditRepository($this->db);
        $hooks = new HookDispatcher();
        $invoices = new InvoiceRepository($this->db);
        $transactions = new TransactionRepository($this->db);
        $payments = new PaymentService($invoices, $transactions, $hooks);
        $creditService = new CreditService($this->credit, $invoices, $transactions, $payments, $hooks);
        $currency = new CurrencyService(new CurrencyRepository($this->db));

        $this->proration = new ProrationService($this->services, $clients, $tax, $this->credit, $creditService, $currency, $this->db, $hooks);

        $this->clientId = $clients->create([
            'email' => 'upgrader@example.test',
            'password' => 'secret123',
            'first_name' => 'Upgrader',
            'last_name' => 'Client',
        ]);

        $groups = new ProductGroupRepository($this->db);
        $products = new ProductRepository($this->db);
        $groupId = $groups->create('Hosting', null);
        $this->productId = $products->create(['product_group_id' => $groupId, 'name' => 'New Plan']);
    }

    /** @return array{id: int, cycleDays: int, unusedDays: int, unusedCredit: float, nextDueDate: string} */
    private function createServiceAtTenDaysRemaining(float $amount): array
    {
        $nextDueDate = (new DateTimeImmutable('+10 days'))->format('Y-m-d');
        $cycleStart = new DateTimeImmutable(ServiceRepository::previousCycleDate($nextDueDate, 'monthly'));
        $cycleDays = $cycleStart->diff(new DateTimeImmutable($nextDueDate))->days;
        $unusedDays = 10;
        $unusedCredit = round(($amount / $cycleDays) * $unusedDays, 2);

        $id = $this->services->create([
            'client_id' => $this->clientId,
            'product_id' => $this->productId,
            'product_name' => 'Old Plan',
            'billing_cycle' => 'monthly',
            'amount' => $amount,
            'status' => 'active',
            'next_due_date' => $nextDueDate,
        ]);

        return compact('id', 'cycleDays', 'unusedDays', 'unusedCredit', 'nextDueDate');
    }

    public function test_none_mode_switches_product_with_no_charge_or_credit(): void
    {
        $service = $this->createServiceAtTenDaysRemaining(30.00);

        $result = $this->proration->upgrade($service['id'], $this->productId, 'New Plan', 50.00, ProrationMode::NONE);

        $this->assertTrue($result['success']);
        $this->assertSame(0.0, $result['chargeAmount']);
        $this->assertSame(0.0, $result['creditAmount']);

        $updated = $this->services->find($service['id']);
        $this->assertSame(50.00, (float) $updated['amount']);
        $this->assertSame($service['nextDueDate'], $updated['next_due_date'], 'none mode does not touch the schedule');
    }

    public function test_full_credit_mode_credits_unused_time_and_resets_cycle(): void
    {
        $service = $this->createServiceAtTenDaysRemaining(30.00);

        $result = $this->proration->upgrade($service['id'], $this->productId, 'New Plan', 50.00, ProrationMode::FULL_CREDIT);

        $this->assertTrue($result['success']);
        $this->assertEqualsWithDelta($service['unusedCredit'], $result['creditAmount'], 0.01);
        $this->assertSame(50.00, $result['chargeAmount']);
        $this->assertArrayHasKey('invoiceId', $result);

        $updated = $this->services->find($service['id']);
        $this->assertSame(50.00, (float) $updated['amount']);
        $expectedNextDue = ServiceRepository::nextCycleDate((new DateTimeImmutable())->format('Y-m-d'), 'monthly');
        $this->assertSame($expectedNextDue, $updated['next_due_date'], 'full credit resets the cycle starting today');

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$result['invoiceId']]);
        $this->assertEqualsWithDelta(50.00, (float) $invoice['total'], 0.01);
    }

    public function test_prorata_mode_charges_only_the_difference_on_upgrade(): void
    {
        $service = $this->createServiceAtTenDaysRemaining(30.00);
        $newValuePerDay = 60.00 / $service['cycleDays'];
        $expectedDiff = round(($newValuePerDay * $service['unusedDays']) - $service['unusedCredit'], 2);

        $result = $this->proration->upgrade($service['id'], $this->productId, 'New Plan', 60.00, ProrationMode::PRORATA);

        $this->assertTrue($result['success']);
        $this->assertEqualsWithDelta($expectedDiff, $result['chargeAmount'], 0.01);
        $this->assertSame(0.0, $result['creditAmount']);

        $updated = $this->services->find($service['id']);
        $this->assertSame(60.00, (float) $updated['amount']);
        $this->assertSame($service['nextDueDate'], $updated['next_due_date'], 'prorata keeps the existing renewal schedule');
    }

    public function test_prorata_mode_credits_the_difference_on_downgrade(): void
    {
        $service = $this->createServiceAtTenDaysRemaining(30.00);
        $newValuePerDay = 15.00 / $service['cycleDays'];
        $newProratedCost = $newValuePerDay * $service['unusedDays'];
        $expectedCredit = round($service['unusedCredit'] - $newProratedCost, 2);

        $result = $this->proration->upgrade($service['id'], $this->productId, 'New Plan', 15.00, ProrationMode::PRORATA);

        $this->assertTrue($result['success']);
        $this->assertSame(0.0, $result['chargeAmount']);
        $this->assertEqualsWithDelta($expectedCredit, $result['creditAmount'], 0.01);
        $this->assertEqualsWithDelta($expectedCredit, $this->credit->balance($this->clientId), 0.01);
    }

    public function test_upgrade_on_a_non_active_service_is_rejected(): void
    {
        $service = $this->createServiceAtTenDaysRemaining(30.00);
        $this->services->suspend($service['id']);

        $result = $this->proration->upgrade($service['id'], $this->productId, 'New Plan', 50.00, ProrationMode::NONE);

        $this->assertFalse($result['success']);
    }
}
