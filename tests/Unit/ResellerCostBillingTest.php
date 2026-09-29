<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerCostBillingJob;
use CodeVault\Reseller\ResellerCostRepository;
use CodeVault\Reseller\ResellerCostService;
use CodeVault\Reseller\ResellerLedgerRepository;
use CodeVault\Reseller\ResellerLedgerService;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * Billing a store for the cost of the orders it took.
 *
 * Orders are inserted directly rather than placed through checkout: Phase 3
 * already proves the storefront records cost_total correctly
 * (ResellerStoreCheckoutTest), and what is under test here is the billing layer
 * above it — which periods are billable, in what currency, exactly once.
 *
 * The three properties worth defending:
 *
 *  - **Once.** Running twice must bill once, and a run after a missed month must
 *    bill the month that was missed. That has to come from the data (the stamp
 *    on the order), not from remembering what the last run did.
 *  - **In the right currency.** Cost is stored in the CUSTOMER's order currency,
 *    which differs per order, and the reseller is invoiced in their own.
 *  - **Without leaking our clients.** A store's customer can be one of our own
 *    clients, so an invoice line names the order, never the person.
 */
final class ResellerCostBillingTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private SettingsRepository $settings;
    private CurrencyService $currency;
    private ResellerCostRepository $costs;
    private ResellerCostService $service;
    private ResellerStoreRepository $storeRepo;
    private ResellerCostBillingJob $job;
    private InvoiceRepository $invoices;
    private int $resellerClientId;
    private int $customerId;
    private int $storeId;
    private int $ngnId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $configDir = sys_get_temp_dir() . '/codevault-reseller-cost-' . uniqid();
        mkdir($configDir);
        $config = new Config($configDir);

        $this->clients = new ClientRepository($this->db);
        $this->settings = new SettingsRepository($this->db);
        $this->currency = new CurrencyService(new CurrencyRepository($this->db));

        $this->storeRepo = new ResellerStoreRepository($this->db);
        $stores = new ResellerStoreService(
            $this->storeRepo,
            new ResellerStoreLocator($this->storeRepo, $config),
            new DomainVerifier()
        );

        $this->costs = new ResellerCostRepository($this->db);
        $this->service = new ResellerCostService($this->costs, $this->storeRepo, $this->clients, $this->currency);

        $this->resellerClientId = $this->clients->create([
            'email' => 'store-owner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Store',
            'last_name' => 'Owner',
        ]);

        $this->customerId = $this->clients->create([
            'email' => 'shopper@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Shopper',
            'last_name' => 'Person',
        ]);

        $this->storeId = (int) $stores->openForClient($this->resellerClientId, 'Acme')['store']['id'];

        $this->ngnId = (new CurrencyRepository($this->db))->create('NGN', 'N', 1490.0);

        $this->invoices = new InvoiceRepository($this->db);

        $this->job = new ResellerCostBillingJob(
            $this->service,
            $this->costs,
            $this->currency,
            new TaxCalculator(new TaxRuleRepository($this->db), new VatNumberValidator(), new TaxSettings($this->settings)),
            $this->settings,
            $this->db,
            new HookDispatcher(),
            // The job also debits the reseller's account now, so the account and
            // the invoice it raises stay in step by construction rather than by a
            // second sweep remembering to look.
            new ResellerLedgerService(
                new ResellerLedgerRepository($this->db),
                $this->storeRepo,
                $this->clients,
                $this->currency,
                $this->settings
            ),
            // ...and settles the invoice itself, so the dunning sweep cannot chase
            // a bill that has already been taken off the reseller's balance.
            $this->invoices
        );
    }

    // ---------------------------------------------------------------- once

    public function test_a_closed_month_is_invoiced_for_the_cost_of_the_stores_orders(): void
    {
        $first = $this->order(30.0, $this->placedIn(1, 5));
        $second = $this->order(45.0, $this->placedIn(1, 20));

        $this->job->handle();

        $invoice = $this->onlyInvoice();
        $this->assertEqualsWithDelta(75.0, (float) $invoice['subtotal'], 0.001);

        // Settled, not owed. The cost came off the reseller's balance in the same
        // pass that raised this invoice (see the settlement tests below), so
        // leaving it 'unpaid' is what let the dunning sweep chase it.
        $this->assertSame('paid', $invoice['status']);

        // Both orders are stamped with the invoice that billed them, which is
        // what makes the next run a no-op.
        $this->assertSame((int) $invoice['id'], $this->stampOn($first));
        $this->assertSame((int) $invoice['id'], $this->stampOn($second));
    }

    public function test_running_twice_bills_once(): void
    {
        $this->order(30.0, $this->placedIn(1, 5));

        $this->job->handle();
        $this->job->handle();
        $this->job->handle();

        $this->assertSame(1, $this->invoiceCount());
    }

    public function test_a_missed_month_is_billed_by_the_next_run(): void
    {
        // Nothing billed for three months, then one run. Each closed month is
        // its own invoice — a gap produces the invoices that were missed, not a
        // single catch-up figure indistinguishable from a spike in sales.
        $this->order(10.0, $this->placedIn(3, 10));
        $this->order(20.0, $this->placedIn(2, 10));
        $this->order(30.0, $this->placedIn(1, 10));

        $this->job->handle();

        $this->assertSame(3, $this->invoiceCount());
        $this->assertEqualsWithDelta(60.0, $this->invoiceSubtotalSum(), 0.001);
    }

    public function test_this_months_orders_are_not_billed_yet(): void
    {
        $this->order(30.0, $this->placedIn(0, 1));

        $this->job->handle();

        $this->assertSame(0, $this->invoiceCount());
    }

    public function test_platform_orders_are_never_billed(): void
    {
        // A sale at our own checkout: no store, no cost. It must be invisible to
        // this job — our revenue from the platform is not a reseller's debt.
        $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, created_at, updated_at) VALUES (?, NULL, ?, ?, NULL, ?, ?)',
            [$this->customerId, 'active', 100.0, $this->placedIn(1, 5), $this->placedIn(1, 5)]
        );

        $this->job->handle();

        $this->assertSame(0, $this->invoiceCount());
    }

    public function test_auto_billing_can_be_switched_off(): void
    {
        $this->order(30.0, $this->placedIn(1, 5));
        $this->settings->set('reseller.billing_auto', '0');

        $this->job->handle();

        $this->assertSame(0, $this->invoiceCount());
        $this->assertSame(0, $this->billedCount());
    }

    public function test_mark_billed_claims_an_order_only_once(): void
    {
        $orderId = $this->order(30.0, $this->placedIn(1, 5));
        $first = $this->makeInvoice();
        $second = $this->makeInvoice();

        // The second claim is a no-op, and the first one stands: whoever stamped
        // the order owns it.
        $this->assertSame(1, $this->costs->markBilled([$orderId], $first));
        $this->assertSame(0, $this->costs->markBilled([$orderId], $second));
        $this->assertSame($first, $this->stampOn($orderId));
    }

    public function test_an_order_cannot_be_stamped_with_an_invoice_that_does_not_exist(): void
    {
        // The foreign key is the last line of defence for the audit link: a
        // stamp that pointed at a non-existent invoice would show an order as
        // billed while no document charged for it.
        $orderId = $this->order(30.0, $this->placedIn(1, 5));

        $this->expectException(\PDOException::class);

        $this->costs->markBilled([$orderId], 999999);
    }

    // ----------------------------------------------------------- currency

    public function test_an_order_in_the_base_currency_is_converted_into_the_resellers_currency(): void
    {
        // The reseller trades in NGN; the order was placed in the base currency.
        $this->db->update('UPDATE clients SET currency_id = ? WHERE id = ?', [$this->ngnId, $this->resellerClientId]);
        $this->order(10.0, $this->placedIn(1, 5));

        $this->job->handle();

        $invoice = $this->onlyInvoice();
        $this->assertEqualsWithDelta(14900.0, (float) $invoice['subtotal'], 0.01);
        $this->assertSame($this->ngnId, (int) $invoice['currency_id']);
        $this->assertSame(1.0, (float) $invoice['currency_rate']);
    }

    public function test_a_denominated_order_is_recovered_to_base_before_conversion(): void
    {
        // The reseller's own currency is the base, and the order was denominated
        // in NGN at write time (currency_rate = 1.0 means "already in NGN"), so
        // the figure must be divided by NGN's rate to recover the base amount —
        // not multiplied, and not taken at face value as 14,900.
        $this->order(14900.0, $this->placedIn(1, 5), $this->ngnId, 1.0);

        $this->job->handle();

        $this->assertEqualsWithDelta(10.0, (float) $this->onlyInvoice()['subtotal'], 0.001);
    }

    public function test_two_orders_in_different_currencies_are_summed_correctly(): void
    {
        $this->db->update('UPDATE clients SET currency_id = ? WHERE id = ?', [$this->ngnId, $this->resellerClientId]);

        // 10 base, and 14,900 NGN which is the same 10 base.
        $this->order(10.0, $this->placedIn(1, 5));
        $this->order(14900.0, $this->placedIn(1, 9), $this->ngnId, 1.0);

        $this->job->handle();

        $this->assertEqualsWithDelta(29800.0, (float) $this->onlyInvoice()['subtotal'], 0.01);
    }

    // ------------------------------------------------------------ minimum

    public function test_a_month_below_the_minimum_is_carried_into_the_next_invoice(): void
    {
        $this->settings->set('reseller.billing_minimum', '50');
        $small = $this->order(10.0, $this->placedIn(2, 10));
        $large = $this->order(60.0, $this->placedIn(1, 10));

        $this->job->handle();

        // One invoice carrying both months, not a 10.00 invoice nobody wants and
        // not a lost 10.00 either.
        $this->assertSame(1, $this->invoiceCount());
        $this->assertEqualsWithDelta(70.0, (float) $this->onlyInvoice()['subtotal'], 0.001);
        $this->assertSame(2, $this->lineCount((int) $this->onlyInvoice()['id']));
        $this->assertGreaterThan(0, $this->stampOn($small));
        $this->assertGreaterThan(0, $this->stampOn($large));
    }

    public function test_a_zero_cost_month_does_not_raise_an_invoice(): void
    {
        // A 100%-discounted order costs us nothing, so there is nothing to bill
        // — but the order stays visibly unbilled rather than being marked as if
        // it had been charged.
        $orderId = $this->order(0.0, $this->placedIn(1, 10));

        $this->job->handle();

        $this->assertSame(0, $this->invoiceCount());
        $this->assertSame(0, $this->stampOn($orderId));
    }

    // -------------------------------------------------------------- terms

    public function test_the_due_date_follows_the_configured_terms(): void
    {
        $this->settings->set('reseller.billing_due_days', '14');
        $this->order(30.0, $this->placedIn(1, 5));

        $this->job->handle();

        $expected = (new DateTimeImmutable('+14 days'))->format('Y-m-d');
        $this->assertSame($expected, substr((string) $this->onlyInvoice()['due_date'], 0, 10));
    }

    // ------------------------------------------------------------ privacy

    public function test_an_invoice_line_never_names_the_customer(): void
    {
        $orderId = $this->order(30.0, $this->placedIn(1, 5));

        $this->job->handle();

        $lines = $this->db->select(
            'SELECT description FROM invoice_items WHERE invoice_id = ?',
            [(int) $this->onlyInvoice()['id']]
        );

        $this->assertNotSame([], $lines);

        foreach ($lines as $line) {
            $description = (string) $line['description'];
            $this->assertStringNotContainsString('shopper@example.test', $description);
            $this->assertStringNotContainsString('Shopper', $description);
        }

        // The line identifies the charge by our own order number instead.
        $this->assertStringContainsString((string) $orderId, (string) $lines[0]['description']);
    }

    // -------------------------------------------------- reporting / arrears

    public function test_the_summary_separates_billed_from_unbilled(): void
    {
        $this->order(30.0, $this->placedIn(1, 5));
        $this->order(45.0, $this->placedIn(0, 1));

        $before = $this->service->storeSummaries();
        $this->assertCount(1, $before);
        $this->assertEqualsWithDelta(0.0, (float) $before[0]['billed'], 0.001);
        $this->assertEqualsWithDelta(75.0, (float) $before[0]['unbilled'], 0.001);
        $this->assertEqualsWithDelta(75.0, (float) $before[0]['accrued'], 0.001);

        $this->job->handle();

        $after = $this->service->storeSummaries();
        $this->assertEqualsWithDelta(30.0, (float) $after[0]['billed'], 0.001);
        $this->assertEqualsWithDelta(45.0, (float) $after[0]['unbilled'], 0.001);
        $this->assertEqualsWithDelta(75.0, (float) $after[0]['accrued'], 0.001);
    }

    public function test_arrears_is_empty_because_a_cost_invoice_is_settled_not_owed(): void
    {
        // This test used to assert the opposite -- that a freshly billed cost
        // invoice appears in arrears -- and that assertion WAS the bug: the
        // invoice was left unpaid after its full amount had already been taken
        // off the reseller's balance, so the dunning sweep chased a bill that had
        // been settled and added a late fee the ledger never saw.
        $this->order(30.0, $this->placedIn(1, 5));
        $this->job->handle();

        $this->assertSame([], $this->service->arrears());

        // The negative control, so this cannot pass merely because arrears() is
        // broken: the very same invoice, forced back to unpaid, IS listed. That is
        // what the sweep would have seen on every run before the fix.
        $this->db->update("UPDATE invoices SET status = 'unpaid' WHERE id = ?", [(int) $this->onlyInvoice()['id']]);

        $arrears = $this->service->arrears();
        $this->assertCount(1, $arrears, 'arrears() must be capable of listing an unpaid cost invoice');
        $this->assertEqualsWithDelta(30.0, (float) $arrears[0]['total'], 0.001);
        $this->assertSame(1, (int) $arrears[0]['order_count']);
    }

    // ------------------------------------------------------- settled, not owed

    public function test_the_cost_invoice_is_settled_as_soon_as_it_is_billed(): void
    {
        $this->order(30.0, $this->placedIn(1, 5));

        $this->job->handle();

        $invoice = $this->onlyInvoice();

        // Paid, with paid_at stamped -- several reporting queries are keyed on
        // "status = paid AND paid_at >= ?", so a status without the timestamp
        // would silently vanish from those reports.
        $this->assertSame('paid', (string) $invoice['status']);
        $this->assertNotNull($invoice['paid_at']);
    }

    public function test_a_settled_cost_invoice_is_never_offered_to_the_dunning_sweep(): void
    {
        $this->order(30.0, $this->placedIn(1, 5));
        $this->job->handle();

        // The job bills with terms (default 7 days), so nothing is overdue yet.
        // Age the invoice past its due date to reach the state the sweep looks for.
        $this->db->update(
            'UPDATE invoices SET due_date = ? WHERE id = ?',
            [(new DateTimeImmutable('-1 day'))->format('Y-m-d'), (int) $this->onlyInvoice()['id']]
        );

        // overdue() is nothing more than "unpaid and past due", and it is the ONLY
        // input to DunningJob. Not being in it is the whole guarantee: no reminder
        // email to the reseller, and no 5% late fee that was never debited.
        $this->assertSame([], $this->invoices->overdue());

        // The negative control: the same aged invoice, forced back to unpaid, IS
        // overdue. Without this the test could pass simply because overdue() was
        // broken, or because the due date never took.
        $this->db->update("UPDATE invoices SET status = 'unpaid' WHERE id = ?", [(int) $this->onlyInvoice()['id']]);

        $overdue = $this->invoices->overdue();
        $this->assertCount(1, $overdue, 'the aged invoice must be reachable by the sweep when unpaid');
    }

    public function test_a_settled_cost_invoice_stays_settled_and_is_not_re_stamped_across_runs(): void
    {
        // I first wrote a test here asserting that an invoice whose debit did NOT
        // post stays unpaid -- and it could not work: the job only ever raises
        // invoices from duePeriods(), which requires a store, so the "no debit"
        // branch is unreachable through this job. The test failed by asserting a
        // state the code cannot produce. This is the reachable version of the
        // property that matters: settling is a ONE-WAY, once-only transition.
        $this->order(30.0, $this->placedIn(1, 5));

        $this->job->handle();
        $afterFirst = $this->onlyInvoice();

        $this->job->handle();
        $this->job->handle();

        $afterThird = $this->onlyInvoice();

        $this->assertSame((int) $afterFirst['id'], (int) $afterThird['id'], 'no second invoice on a later run');
        $this->assertSame('paid', (string) $afterThird['status']);
        $this->assertSame(
            (string) $afterFirst['paid_at'],
            (string) $afterThird['paid_at'],
            'markPaid() is guarded by "AND status = unpaid", so a later run must not re-stamp the settlement'
        );
    }

    public function test_arrears_never_include_a_customers_invoice(): void
    {
        // An ordinary unpaid invoice of our own is not a reseller's debt, even
        // when the client happens to own a store.
        $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->resellerClientId, 'unpaid', 99.0, 0.0, 99.0, '2026-01-01', $this->placedIn(1, 5), $this->placedIn(1, 5)]
        );

        $this->assertSame([], $this->service->arrears());
    }

    // -------------------------------------------------------------- helpers

    /** An order at the store with a known cost, placed at a known time. */
    private function order(float $cost, string $placedAt, ?int $currencyId = null, float $rate = 1.0): int
    {
        return (int) $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $this->storeId, 'active', $cost, $cost, $currencyId, $rate, $placedAt, $placedAt]
        );
    }

    /** A timestamp inside a calendar month, $monthsAgo before the current one. */
    private function placedIn(int $monthsAgo, int $day): string
    {
        $month = (new DateTimeImmutable('first day of this month 12:00:00'))->modify("-{$monthsAgo} month");
        $safeDay = min($day, (int) $month->format('t'));

        return $month
            ->setDate((int) $month->format('Y'), (int) $month->format('m'), $safeDay)
            ->format('Y-m-d H:i:s');
    }

    /** An invoice row the cost stamp may legally point at. */
    private function makeInvoice(): int
    {
        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->resellerClientId, 'unpaid', 1.0, 0.0, 1.0, '2026-01-01', $this->placedIn(1, 5), $this->placedIn(1, 5)]
        );
    }

    /** @return array<string, mixed> */
    private function onlyInvoice(): array
    {
        $invoices = $this->db->select(
            'SELECT * FROM invoices WHERE client_id = ? ORDER BY id ASC',
            [$this->resellerClientId]
        );

        $this->assertCount(1, $invoices, 'Expected exactly one cost invoice for the store owner.');

        return $invoices[0];
    }

    private function invoiceCount(): int
    {
        return (int) ($this->db->selectOne(
            'SELECT COUNT(*) AS c FROM invoices WHERE client_id = ?',
            [$this->resellerClientId]
        )['c'] ?? 0);
    }

    private function invoiceSubtotalSum(): float
    {
        return (float) ($this->db->selectOne(
            'SELECT COALESCE(SUM(subtotal), 0) AS s FROM invoices WHERE client_id = ?',
            [$this->resellerClientId]
        )['s'] ?? 0.0);
    }

    private function lineCount(int $invoiceId): int
    {
        return (int) ($this->db->selectOne(
            'SELECT COUNT(*) AS c FROM invoice_items WHERE invoice_id = ?',
            [$invoiceId]
        )['c'] ?? 0);
    }

    private function stampOn(int $orderId): int
    {
        return (int) ($this->db->selectOne(
            'SELECT COALESCE(reseller_cost_invoice_id, 0) AS i FROM orders WHERE id = ?',
            [$orderId]
        )['i'] ?? 0);
    }

    private function billedCount(): int
    {
        return (int) ($this->db->selectOne(
            'SELECT COUNT(*) AS c FROM orders WHERE reseller_id = ? AND reseller_cost_invoice_id IS NOT NULL',
            [$this->storeId]
        )['c'] ?? 0);
    }
}
