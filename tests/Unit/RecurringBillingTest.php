<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\RecurringBillingService;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class RecurringBillingTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private RecurringBillingService $billing;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->services = new ServiceRepository($this->db);
        $clients = new ClientRepository($this->db);
        $tax = new TaxCalculator(new TaxRuleRepository($this->db), new VatNumberValidator(), new TaxSettings(new SettingsRepository($this->db)));
        $currency = new CurrencyService(new CurrencyRepository($this->db));

        $this->billing = new RecurringBillingService($this->services, $clients, $tax, $currency, $this->db, new HookDispatcher());

        $this->clientId = $clients->create([
            'email' => 'renewal@example.test',
            'password' => 'secret123',
            'first_name' => 'Renewal',
            'last_name' => 'Client',
        ]);
    }

    private function createService(string $nextDueDate, string $cycle = 'monthly'): int
    {
        $groups = new \CodeVault\Catalog\ProductGroupRepository($this->db);
        $products = new \CodeVault\Catalog\ProductRepository($this->db);
        $groupId = $groups->create('Hosting', null);
        $productId = $products->create(['product_group_id' => $groupId, 'name' => 'Starter']);

        $id = $this->services->create([
            'client_id' => $this->clientId,
            'product_id' => $productId,
            'product_name' => 'Starter',
            'billing_cycle' => $cycle,
            'amount' => 9.99,
            'status' => 'active',
            'next_due_date' => $nextDueDate,
        ]);

        return $id;
    }

    public function test_service_due_within_window_generates_an_invoice(): void
    {
        $dueDate = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
        $this->createService($dueDate);

        $generated = $this->billing->generateDueInvoices(14);

        $this->assertCount(1, $generated);

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$generated[0]]);
        $this->assertSame('unpaid', $invoice['status']);
        $this->assertEqualsWithDelta(9.99, (float) $invoice['total'], 0.001);
        $this->assertSame($dueDate, $invoice['due_date']);
    }

    public function test_service_outside_window_is_not_billed_yet(): void
    {
        $this->createService((new DateTimeImmutable('+30 days'))->format('Y-m-d'));

        $this->assertCount(0, $this->billing->generateDueInvoices(14));
    }

    public function test_advances_next_due_date_by_one_cycle(): void
    {
        $dueDate = (new DateTimeImmutable('+2 days'))->format('Y-m-d');
        $serviceId = $this->createService($dueDate, 'monthly');

        $this->billing->generateDueInvoices(14);

        $service = $this->services->find($serviceId);
        $this->assertSame(ServiceRepository::nextCycleDate($dueDate, 'monthly'), $service['next_due_date']);
    }

    public function test_running_twice_does_not_double_bill(): void
    {
        $dueDate = (new DateTimeImmutable('+2 days'))->format('Y-m-d');
        $this->createService($dueDate);

        $first = $this->billing->generateDueInvoices(14);
        // Simulate a second cron tick before the due date field would
        // naturally change — the idempotency guard keys on (service_id, due_date).
        $second = $this->billing->generateDueInvoices(14);

        $this->assertCount(1, $first);
        // After the first run the service's next_due_date already advanced,
        // so the second run bills the *new* cycle only if it's also within
        // the window — assert no duplicate invoice exists for the original date.
        $invoicesForOriginalDate = $this->db->select('SELECT id FROM invoices WHERE due_date = ?', [$dueDate]);
        $this->assertCount(1, $invoicesForOriginalDate);
    }

    public function test_suspended_service_is_not_billed(): void
    {
        $serviceId = $this->createService((new DateTimeImmutable('+2 days'))->format('Y-m-d'));
        $this->services->suspend($serviceId);

        $this->assertCount(0, $this->billing->generateDueInvoices(14));
    }

    public function test_fires_invoice_created_hook(): void
    {
        $fired = [];
        $groups = new \CodeVault\Catalog\ProductGroupRepository($this->db);
        $products = new \CodeVault\Catalog\ProductRepository($this->db);
        $clients = new ClientRepository($this->db);
        $tax = new TaxCalculator(new TaxRuleRepository($this->db), new VatNumberValidator(), new TaxSettings(new SettingsRepository($this->db)));
        $hooks = new HookDispatcher();
        $hooks->register(HookPoints::INVOICE_CREATED, function (array $p) use (&$fired) {
            $fired[] = $p;
        });
        $currency = new CurrencyService(new CurrencyRepository($this->db));
        $billing = new RecurringBillingService($this->services, $clients, $tax, $currency, $this->db, $hooks);

        $this->createService((new DateTimeImmutable('+1 day'))->format('Y-m-d'));
        $billing->generateDueInvoices(14);

        $this->assertCount(1, $fired);
    }
}
