<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerLedgerRepository;
use CodeVault\Reseller\ResellerLedgerService;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The reseller's running account: what we owe a store, and why.
 *
 * Orders and invoices are inserted directly rather than driven through checkout.
 * Phase 3 already proves the storefront records reseller_id and cost_total
 * (ResellerStoreCheckoutTest) and Phase 4 proves the cost invoice is raised
 * (ResellerCostBillingTest); what is under test here is the account those two
 * feed, and driving a real checkout would re-test both of them.
 *
 * The properties worth defending, in order of how much money they represent:
 *
 *  - **Nothing is credited until the money is actually ours.** An unpaid invoice
 *    must credit nothing, and that is checked from the invoice's STATE, not
 *    merely assumed from the hook that happens to call us.
 *  - **The account is kept in the BASE unit.** A receipt in the customer's
 *    currency must land as its base equivalent, because the reseller — not we —
 *    carries the exchange movement between collection and payout.
 *  - **Once.** A re-fired hook must not credit a second time; the unique key on
 *    (kind, invoice_id) makes that a property of the data, not of the run.
 *  - **Derived, never stored.** The balance is SUM(amount), so an entry added by
 *    any route moves it. There is no balance column to fall out of step.
 *  - **Two numbers, not one.** The holding period means "we owe you X" and "you
 *    can take Y today" are genuinely different, and both must be reported.
 */
final class ResellerLedgerTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private SettingsRepository $settings;
    private CurrencyService $currency;
    private ResellerLedgerRepository $ledger;
    private ResellerLedgerService $service;
    private int $resellerClientId;
    private int $customerId;
    private int $storeId;
    private int $ngnId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $configDir = sys_get_temp_dir() . '/codevault-ledger-' . uniqid();
        mkdir($configDir);
        $config = new Config($configDir);

        $this->clients = new ClientRepository($this->db);
        $this->settings = new SettingsRepository($this->db);
        $this->currency = new CurrencyService(new CurrencyRepository($this->db));

        $storeRepo = new ResellerStoreRepository($this->db);
        $stores = new ResellerStoreService(
            $storeRepo,
            new ResellerStoreLocator($storeRepo, $config),
            new DomainVerifier()
        );

        $this->ledger = new ResellerLedgerRepository($this->db);
        $this->service = new ResellerLedgerService(
            $this->ledger,
            $storeRepo,
            $this->clients,
            $this->currency,
            $this->settings
        );

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
    }

    // ------------------------------------------------- claimed on payment only

    public function test_a_paid_store_order_credits_the_retail_we_collected(): void
    {
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00');

        $entryId = $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        $this->assertNotNull($entryId, 'a paid store order must credit the account');
        $this->assertEqualsWithDelta(120.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_an_unpaid_store_order_credits_nothing(): void
    {
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00', 'unpaid');

        // Called directly, which is the point: the hook only fires on payment,
        // so if the service trusted its caller this would credit a balance funded
        // by money nobody has paid.
        $this->assertNull($this->service->accrueStoreReceipt($order['invoiceId']));
        $this->assertEqualsWithDelta(0.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_an_invoice_that_belongs_to_no_store_credits_nothing(): void
    {
        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$this->customerId, 'active', 99.0, '2026-01-10 09:00:00', '2026-01-10 09:00:00']
        );

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $orderId, 'paid', 99.0, 0.0, 99.0, '2026-01-10', '2026-01-10 09:00:00', '2026-01-10 09:00:00']
        );

        // Most of our invoices are ours. That must be the quiet case.
        $this->assertNull($this->service->accrueStoreReceipt($invoiceId));
        $this->assertEqualsWithDelta(0.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_crediting_the_same_invoice_twice_credits_once(): void
    {
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00');

        $this->assertNotNull($this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00'));
        $this->assertNull(
            $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00'),
            'a re-fired hook must be a no-op'
        );

        $this->assertEqualsWithDelta(120.0, $this->ledger->balance($this->storeId), 0.001);
    }

    // ------------------------------------------------------- the base unit

    public function test_a_receipt_in_the_customers_currency_is_credited_in_the_base_unit(): void
    {
        // The customer paid 149,000 NGN at 1490 NGN to the base unit: 100 base.
        // This is the FX decision in one assertion -- we hold a base figure, and
        // that figure is what the account is denominated in.
        $order = $this->storeOrder(149000.0, '2026-01-10 09:00:00', 'paid', $this->ngnId, 1.0);

        $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        $this->assertEqualsWithDelta(100.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_the_balance_is_shown_in_the_resellers_currency_and_moves_with_the_rate(): void
    {
        // A store billed in NGN: their account currency is NGN.
        $this->clients->updateCurrency($this->resellerClientId, $this->ngnId);
        $order = $this->storeOrder(100.0, '2026-01-10 09:00:00');
        $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        $before = $this->service->accountFor($this->storeId, '2026-01-15 00:00:00');
        $this->assertEqualsWithDelta(100.0, $before['balance_base'], 0.001);
        $this->assertEqualsWithDelta(149000.0, $before['balance'], 0.01);

        // The NGN weakens. The base figure is untouched; only what it is worth
        // in their currency changes. THE RESELLER CARRIES THIS, by decision --
        // so this test is the specification, not an accident of rounding.
        $this->db->update('UPDATE currencies SET exchange_rate = ? WHERE id = ?', [1600.0, $this->ngnId]);

        $after = $this->service->accountFor($this->storeId, '2026-01-15 00:00:00');
        $this->assertEqualsWithDelta(100.0, $after['balance_base'], 0.001, 'the base balance must not move');
        $this->assertEqualsWithDelta(160000.0, $after['balance'], 0.01);
    }

    // ------------------------------------------------------------- the debit

    public function test_a_cost_invoice_is_debited_from_the_balance(): void
    {
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00');
        $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        $costInvoiceId = $this->costInvoice(20.0, [$order['orderId']]);
        $this->service->recordCostInvoice($costInvoiceId, '2026-02-01 00:00:00');

        // 120 collected less 20 of cost = 100 of margin, which is the number the
        // whole account exists to produce.
        $this->assertEqualsWithDelta(100.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_a_cost_invoice_is_never_debited_twice(): void
    {
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00');
        $costInvoiceId = $this->costInvoice(20.0, [$order['orderId']]);

        $this->assertNotNull($this->service->recordCostInvoice($costInvoiceId, '2026-02-01 00:00:00'));
        $this->assertNull($this->service->recordCostInvoice($costInvoiceId, '2026-02-01 00:00:00'));
        $this->assertEqualsWithDelta(-20.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_a_cost_invoice_covering_two_stores_posts_nothing(): void
    {
        // Phase 4 raises one cost invoice per store, so an invoice whose orders
        // span two stores means something already went wrong. Debiting whichever
        // store a MIN() happened to pick would take money from the wrong
        // reseller; posting nothing is the only safe answer.
        $mine = $this->storeOrder(120.0, '2026-01-10 09:00:00');

        // A SECOND RESELLER, not a second store for the same one.
        // openForClient() returns the existing store when the client already has
        // one, so asking the same client for a second store silently hands back
        // the SAME store -- the two orders then share a reseller, the invoice
        // looks unambiguous, and the guard this test exists to prove is never
        // exercised at all.
        $secondOwnerId = $this->clients->create([
            'email' => 'other-owner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Other',
            'last_name' => 'Owner',
        ]);

        $configDir = sys_get_temp_dir() . '/codevault-ledger-other-' . uniqid();
        mkdir($configDir);

        $otherStoreRepo = new ResellerStoreRepository($this->db);
        $otherStoreId = (int) (new ResellerStoreService(
            $otherStoreRepo,
            new ResellerStoreLocator($otherStoreRepo, new Config($configDir)),
            new DomainVerifier()
        ))->openForClient($secondOwnerId, 'Other')['store']['id'];

        $otherOrder = (int) $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $otherStoreId, 'active', 50.0, 40.0, '2026-01-10 09:00:00', '2026-01-10 09:00:00']
        );

        $costInvoiceId = $this->costInvoice(30.0, [$mine['orderId'], $otherOrder]);

        $this->assertNull($this->service->recordCostInvoice($costInvoiceId, '2026-02-01 00:00:00'));
        $this->assertEqualsWithDelta(0.0, $this->ledger->balance($this->storeId), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->ledger->balance($otherStoreId), 0.001);
    }

    // ------------------------------------------------- the balance is derived

    public function test_the_balance_is_the_sum_of_its_entries_not_a_stored_figure(): void
    {
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00');
        $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        // An entry posted by a route the service did not see must still move the
        // balance. If a balance column existed and were authoritative, this would
        // be where it started disagreeing with the entries behind it.
        $this->ledger->append([
            'reseller_id' => $this->storeId,
            'client_id' => $this->resellerClientId,
            'kind' => 'adjustment',
            'amount' => -5.5,
            'withdrawable_at' => null,
            'order_id' => null,
            'invoice_id' => null,
            'payout_id' => null,
            'description' => 'Manual correction',
            'admin_id' => null,
            'created_at' => '2026-01-11 00:00:00',
        ]);

        $this->assertEqualsWithDelta(114.5, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_a_store_that_priced_below_our_cost_has_a_negative_balance(): void
    {
        $order = $this->storeOrder(10.0, '2026-01-10 09:00:00');
        $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        $costInvoiceId = $this->costInvoice(25.0, [$order['orderId']]);
        $this->service->recordCostInvoice($costInvoiceId, '2026-02-01 00:00:00');

        $account = $this->service->accountFor($this->storeId, '2026-02-01 00:00:00');
        $this->assertEqualsWithDelta(-15.0, $account['balance_base'], 0.001);
        $this->assertTrue($account['in_arrears'], 'they owe us, and the report must say so');
        $this->assertFalse($account['can_withdraw'], 'a negative balance is not withdrawable');
    }

    // ------------------------------------------------------- the holding period

    public function test_a_receipt_inside_its_holding_period_counts_but_cannot_be_withdrawn(): void
    {
        $this->settings->set('reseller.payout_minimum', '0.00');
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00');
        $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        // Two numbers, and they are meant to differ: the money is theirs and they
        // should see it, but it is not yet safe to send.
        $account = $this->service->accountFor($this->storeId, '2026-01-15 00:00:00');
        $this->assertEqualsWithDelta(120.0, $account['balance_base'], 0.001);
        $this->assertEqualsWithDelta(0.0, $account['withdrawable_base'], 0.001);
        $this->assertFalse($account['can_withdraw']);
    }

    public function test_a_receipt_becomes_withdrawable_after_the_holding_period(): void
    {
        $this->settings->set('reseller.payout_minimum', '0.00');
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00');
        $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        // The holding period runs from the POSTING TIME, not the calendar day. A
        // receipt posted at 09:00 on 10 Jan matures at 09:00 on 9 Feb, so the
        // boundary is the clock. This test previously asserted midnight and was
        // wrong -- the implementation was right, and a boundary that is only ever
        // probed at midnight would never have caught a wrong one either way.
        $this->assertEqualsWithDelta(
            0.0,
            $this->service->accountFor($this->storeId, '2026-02-09 08:59:59')['withdrawable_base'],
            0.001
        );
        $this->assertEqualsWithDelta(
            120.0,
            $this->service->accountFor($this->storeId, '2026-02-09 09:00:00')['withdrawable_base'],
            0.001
        );
    }

    public function test_a_zero_day_holding_period_makes_funds_withdrawable_at_once(): void
    {
        $this->settings->set('reseller.payout_holding_days', '0');
        $this->settings->set('reseller.payout_minimum', '0.00');
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00');
        $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        $account = $this->service->accountFor($this->storeId, '2026-01-10 09:00:00');
        $this->assertEqualsWithDelta(120.0, $account['withdrawable_base'], 0.001);
        $this->assertTrue($account['can_withdraw']);
    }

    public function test_withdrawing_needs_the_configured_minimum(): void
    {
        $this->settings->set('reseller.payout_minimum', '50.00');

        // Below the minimum: withdrawable, but not yet withdrawable *enough*.
        $small = $this->storeOrder(20.0, '2026-01-10 09:00:00');
        $this->service->accrueStoreReceipt($small['invoiceId'], '2026-01-10 09:00:00');
        $account = $this->service->accountFor($this->storeId, '2026-06-01 00:00:00');
        $this->assertTrue($account['withdrawable_base'] > 0.0, 'the funds are theirs');
        $this->assertFalse($account['can_withdraw'], 'but below the minimum they cannot take them');

        // Above it: a companion assertion, because a refusal that is only ever
        // tested on its own can pass for entirely the wrong reason.
        $big = $this->storeOrder(80.0, '2026-01-10 10:00:00');
        $this->service->accrueStoreReceipt($big['invoiceId'], '2026-01-10 10:00:00');
        $this->assertTrue($this->service->accountFor($this->storeId, '2026-06-01 00:00:00')['can_withdraw']);
    }

    public function test_a_currency_specific_minimum_is_compared_in_base_units(): void
    {
        $this->settings->set('reseller.payout_minimum', '50.00');
        $this->settings->set('reseller.payout_holding_days', '0');
        $this->settings->set('reseller.payout_minimums', '{"NGN":"50000.00"}');
        $this->db->update('UPDATE clients SET currency_id = ? WHERE id = ?', [$this->ngnId, $this->resellerClientId]);

        $sale = $this->storeOrder(40.0, '2026-01-10 09:00:00');
        $this->service->accrueStoreReceipt($sale['invoiceId'], '2026-01-10 09:00:00');

        $account = $this->service->accountFor($this->storeId, '2026-01-10 09:00:00');
        $this->assertEqualsWithDelta(50000.0, (float) $account['minimum'], 0.001);
        $this->assertEqualsWithDelta(50000.0 / 1490.0, (float) $account['minimum_base'], 0.000001);
        $this->assertTrue($account['can_withdraw'], '40 base units exceed the NGN 50,000 minimum at the current rate');

        // Raising only the NGN threshold beyond the same 40-unit balance must
        // refuse the payout without changing what the ledger says is owed.
        $this->settings->set('reseller.payout_minimums', '{"NGN":"65000.00"}');
        $account = $this->service->accountFor($this->storeId, '2026-01-10 09:00:00');
        $this->assertFalse($account['can_withdraw']);
        $this->assertEqualsWithDelta(40.0, (float) $account['balance_base'], 0.001);
    }

    public function test_an_unconfigured_currency_keeps_the_legacy_base_minimum(): void
    {
        $this->settings->set('reseller.payout_minimum', '50.00');
        $this->settings->set('reseller.payout_minimums', '{"NGN":"50000.00"}');

        $account = $this->service->accountFor($this->storeId, '2026-01-10 09:00:00');

        $this->assertEqualsWithDelta(50.0, (float) $account['minimum_base'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $account['minimum'], 0.001);
    }

    public function test_the_legacy_minimum_is_converted_for_an_unconfigured_foreign_currency(): void
    {
        $this->settings->set('reseller.payout_minimum', '50.00');
        $this->settings->set('reseller.payout_minimums', '{}');
        $this->db->update('UPDATE clients SET currency_id = ? WHERE id = ?', [$this->ngnId, $this->resellerClientId]);

        $account = $this->service->accountFor($this->storeId, '2026-01-10 09:00:00');

        $this->assertEqualsWithDelta(50.0, (float) $account['minimum_base'], 0.001);
        $this->assertEqualsWithDelta(74500.0, (float) $account['minimum'], 0.001);
    }

    // ---------------------------------------------------------------- refunds

    public function test_a_refund_appends_a_reversing_entry_and_leaves_the_original(): void
    {
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00');
        $receiptId = $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        // Reverse both sides, as appended entries. The original is never edited or
        // deleted: an append-only ledger has to keep the story, because "why is
        // this balance what it is" is the only question the account exists to answer.
        $this->ledger->append([
            'reseller_id' => $this->storeId,
            'client_id' => $this->resellerClientId,
            'kind' => 'store_receipt',
            'amount' => -120.0,
            'withdrawable_at' => null,
            'order_id' => $order['orderId'],
            'invoice_id' => null,
            'payout_id' => null,
            'description' => 'Reversal: store order #' . $order['orderId'] . ' refunded',
            'admin_id' => null,
            'created_at' => '2026-01-20 00:00:00',
        ]);

        $this->assertEqualsWithDelta(0.0, $this->ledger->balance($this->storeId), 0.001);

        $entries = $this->ledger->entries($this->storeId);
        $this->assertCount(2, $entries, 'the reversal must not replace the original');
        $this->assertContains($receiptId, array_map(static fn (array $e): int => (int) $e['id'], $entries));
    }

    public function test_a_second_reversal_for_one_order_is_allowed(): void
    {
        // Partial refunds are a real thing, which is exactly why the uniqueness
        // guard keys on the invoice and not on the order.
        $order = $this->storeOrder(120.0, '2026-01-10 09:00:00');
        $this->service->accrueStoreReceipt($order['invoiceId'], '2026-01-10 09:00:00');

        foreach ([30.0, 20.0] as $i => $amount) {
            $this->ledger->append([
                'reseller_id' => $this->storeId,
                'client_id' => $this->resellerClientId,
                'kind' => 'store_receipt',
                'amount' => -$amount,
                'withdrawable_at' => null,
                'order_id' => $order['orderId'],
                'invoice_id' => null,
                'payout_id' => null,
                'description' => 'Partial refund ' . ($i + 1),
                'admin_id' => null,
                'created_at' => '2026-01-2' . $i . ' 00:00:00',
            ]);
        }

        $this->assertEqualsWithDelta(70.0, $this->ledger->balance($this->storeId), 0.001);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A store order and the invoice that bills it, so the accrual has something
     * real to read. Returns both ids.
     *
     * @return array{orderId: int, invoiceId: int}
     */
    private function storeOrder(
        float $retail,
        string $at,
        string $status = 'paid',
        ?int $currencyId = null,
        float $rate = 1.0
    ): array {
        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $this->storeId, 'active', $retail, round($retail * 0.8, 2), $currencyId, $rate, $at, $at]
        );

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $orderId, $status, $retail, 0.0, $retail, $currencyId, $rate, substr($at, 0, 10), $at, $at]
        );

        return ['orderId' => $orderId, 'invoiceId' => $invoiceId];
    }

    /**
     * A cost invoice billed to the store, with the given orders stamped as billed
     * by it — the same link ResellerCostRepository uses, seen from the other side.
     *
     * Base-currency (currency_id NULL) so the account's unit is unambiguous in
     * these tests; the conversion itself is covered above.
     *
     * @param array<int, int> $orderIds
     */
    private function costInvoice(float $total, array $orderIds): int
    {
        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->resellerClientId, 'unpaid', $total, 0.0, $total, '2026-02-01', '2026-02-01 00:00:00', '2026-02-01 00:00:00']
        );

        foreach ($orderIds as $orderId) {
            $this->db->update(
                'UPDATE orders SET reseller_cost_invoice_id = ? WHERE id = ?',
                [$invoiceId, (int) $orderId]
            );
        }

        return $invoiceId;
    }
}
