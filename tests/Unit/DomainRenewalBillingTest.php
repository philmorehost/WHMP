<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainRenewalBillingService;
use CodeVault\Domains\DomainRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class DomainRenewalBillingTest extends DatabaseTestCase
{
    private DomainRepository $domains;
    private DomainRenewalBillingService $billing;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->domains = new DomainRepository($this->db);
        $clients = new ClientRepository($this->db);
        $tax = new TaxCalculator(new TaxRuleRepository($this->db), new VatNumberValidator(), new TaxSettings(new SettingsRepository($this->db)));

        $this->billing = new DomainRenewalBillingService(
            $this->domains,
            $clients,
            $tax,
            $this->db,
            new HookDispatcher(),
            new CurrencyService(new CurrencyRepository($this->db))
        );

        $this->clientId = $clients->create([
            'email' => 'domainrenewal@example.test',
            'password' => 'secret123',
            'first_name' => 'Renewal',
            'last_name' => 'Client',
        ]);
    }

    private function createDomain(string $nextDueDate, bool $autoRenew = true, string $status = 'active'): int
    {
        return $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => 'renewbill' . uniqid() . '.test',
            'registrar_slug' => 'local',
            'status' => $status,
            'next_due_date' => $nextDueDate,
            'auto_renew' => $autoRenew ? 1 : 0,
            'amount' => 14.99,
        ]);
    }

    public function test_domain_due_within_window_generates_a_renewal_invoice(): void
    {
        $dueDate = (new DateTimeImmutable('+10 days'))->format('Y-m-d');
        $this->createDomain($dueDate);

        $generated = $this->billing->generateDueInvoices(30);

        $this->assertCount(1, $generated);
        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$generated[0]]);
        $this->assertSame('unpaid', $invoice['status']);
        $this->assertEqualsWithDelta(14.99, (float) $invoice['total'], 0.001);
    }

    public function test_domain_outside_window_is_not_billed_yet(): void
    {
        $this->createDomain((new DateTimeImmutable('+60 days'))->format('Y-m-d'));

        $this->assertCount(0, $this->billing->generateDueInvoices(30));
    }

    public function test_running_twice_does_not_double_bill(): void
    {
        $dueDate = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
        $this->createDomain($dueDate);

        $first = $this->billing->generateDueInvoices(30);
        $second = $this->billing->generateDueInvoices(30);

        $this->assertCount(1, $first);
        $invoicesForDate = $this->db->select('SELECT id FROM invoices WHERE due_date = ?', [$dueDate]);
        $this->assertCount(1, $invoicesForDate);
    }

    public function test_domain_without_auto_renew_is_not_billed(): void
    {
        $this->createDomain((new DateTimeImmutable('+5 days'))->format('Y-m-d'), autoRenew: false);

        $this->assertCount(0, $this->billing->generateDueInvoices(30));
    }

    public function test_non_active_domain_is_not_billed(): void
    {
        $this->createDomain((new DateTimeImmutable('+5 days'))->format('Y-m-d'), autoRenew: true, status: 'pending');

        $this->assertCount(0, $this->billing->generateDueInvoices(30));
    }

    /**
     * redemption_fee is a fresh, never-before-charged domain_pricing catalog
     * read (unlike $domain['amount'], which is already-converted once at
     * registration) — the same "convert once" case as CheckoutService and
     * ProrationService.
     */
    public function test_redemption_fee_is_converted_into_the_clients_currency(): void
    {
        $currencies = new CurrencyRepository($this->db);
        $ngnId = $currencies->create('NGN', '₦', 1490.0000);
        $clients = new ClientRepository($this->db);
        $clients->updateCurrency($this->clientId, $ngnId);

        $pricing = new \CodeVault\Domains\DomainPricingRepository($this->db);
        $pricing->save([
            'tld' => '.test',
            'registrar_slug' => 'local',
            'register_price' => 10.00,
            'transfer_price' => 10.00,
            'renew_price' => 14.99,
            'grace_period_days' => 10,
            'redemption_period_days' => 20,
            'redemption_fee' => 25.00,
        ]);

        $domainId = $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => 'redeem' . uniqid() . '.test',
            'tld' => '.test',
            'registrar_slug' => 'local',
            'status' => 'active',
            'next_due_date' => (new DateTimeImmutable('+5 days'))->format('Y-m-d'),
            // 15 days past expiry: > grace_period_days(10), <= grace+redemption(30).
            'expiry_date' => (new DateTimeImmutable('-15 days'))->format('Y-m-d'),
            'auto_renew' => 1,
            'amount' => 14.99,
        ]);

        $generated = $this->billing->generateDueInvoices(30);

        $this->assertCount(1, $generated);
        $items = $this->db->select('SELECT * FROM invoice_items WHERE invoice_id = ?', [$generated[0]]);
        $redemptionItem = null;
        foreach ($items as $item) {
            if (str_contains((string) $item['description'], 'Redemption Fee')) {
                $redemptionItem = $item;
            }
        }

        $this->assertNotNull($redemptionItem, 'Expected a redemption fee line item.');
        // 25.00 catalog fee converted at 1490 -> 37,250.00
        $this->assertEqualsWithDelta(37250.00, (float) $redemptionItem['amount'], 0.01);
    }
}
