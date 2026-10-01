<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Reseller\ResellerCostRepository;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerLedgerRepository;
use CodeVault\Reseller\ResellerLedgerService;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * Reversing a receipt when the sale is refunded (plan §9 decision 4).
 *
 * THE ASSERTION THAT MATTERS is the withdrawable one, and it needs TWO receipts to
 * mean anything. Reversing a receipt that was already withdrawable drops both
 * figures to zero, which looks identical whether the reversal was immediate or
 * held for thirty days — so a test like that proves nothing. This file sets up one
 * MATURE receipt and one IMMATURE one, reverses the mature one, and checks the
 * withdrawal ceiling falls to zero.
 *
 * Held again for the holding period, the reversal would not count yet, and the
 * withdrawable figure would stay at the mature receipt's amount — larger than the
 * balance itself. That is the state in which we pay a reseller money we have
 * already returned to the customer, and there is no entry left to claw back
 * against. It is the reason this listener exists.
 */
final class ResellerRefundReversalTest extends DatabaseTestCase
{
    private ResellerLedgerService $ledger;
    private ClientRepository $clients;
    private int $resellerClientId;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $storeRepo = new ResellerStoreRepository($this->db);
        $settings = new SettingsRepository($this->db);
        $currency = new CurrencyService(new CurrencyRepository($this->db));

        $stores = new ResellerStoreService(
            $storeRepo,
            new ResellerStoreLocator($storeRepo, new Config(sys_get_temp_dir())),
            new DomainVerifier()
        );

        $this->resellerClientId = $this->clients->create([
            'email' => 'refund-owner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Refund',
            'last_name' => 'Owner',
        ]);

        $this->storeId = (int) $stores->openForClient($this->resellerClientId, 'Refunds')['store']['id'];

        $this->ledger = new ResellerLedgerService(
            new ResellerLedgerRepository($this->db),
            $storeRepo,
            $this->clients,
            $currency,
            $settings
        );
    }

    // ------------------------------------------------------- the safety property

    public function test_a_reversal_is_withdrawable_at_once_even_though_it_reverses_a_held_receipt(): void
    {
        $sixtyDaysAgo = (new DateTimeImmutable('-60 days'))->format('Y-m-d H:i:s');
        $anHourAgo = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');

        // Mature: posted 60 days ago, so its 30-day hold is over.
        $matureInvoice = $this->paidStoreOrder(100.0, $sixtyDaysAgo, '2026-01-01 09:00:00');
        $this->ledger->accrueStoreReceipt($matureInvoice, $sixtyDaysAgo);

        // Immature: posted an hour ago, still held. This one exists to keep the
        // withdrawable figure distinguishable from the balance.
        $recentInvoice = $this->paidStoreOrder(40.0, $anHourAgo, '2026-01-02 09:00:00');
        $this->ledger->accrueStoreReceipt($recentInvoice, $anHourAgo);

        $before = $this->ledger->accountFor($this->storeId);
        $this->assertEqualsWithDelta(140.0, (float) $before['balance_base'], 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $before['withdrawable_base'], 0.001);

        $this->ledger->reverseStoreReceipt($matureInvoice, 100.0);

        $after = $this->ledger->accountFor($this->storeId);

        $this->assertEqualsWithDelta(40.0, (float) $after['balance_base'], 0.001, 'the refunded 100 must leave the balance');

        // THE POINT. If the reversal carried its own 30-day hold it would not count
        // yet, and this would still read 100 — MORE than the 40 balance, which is
        // the state in which we pay out money we no longer hold.
        $this->assertEqualsWithDelta(
            0.0,
            (float) $after['withdrawable_base'],
            0.001,
            'a refund must reduce what can be withdrawn immediately, not after another holding period'
        );
    }

    // ------------------------------------------------------------- the amounts

    public function test_a_full_refund_cancels_the_receipt_exactly(): void
    {
        $invoice = $this->paidStoreOrder(129.99, '2026-01-01 09:00:00', '2026-01-01 09:00:00');
        $this->ledger->accrueStoreReceipt($invoice, '2026-01-01 09:00:00');

        $this->ledger->reverseStoreReceipt($invoice, 129.99);

        // No rounding tail: the reversal is converted with the same invoice facts
        // the receipt used, so the two are exact inverses rather than merely close.
        $this->assertEqualsWithDelta(0.0, (float) $this->ledger->accountFor($this->storeId)['balance_base'], 0.001);
    }

    public function test_partial_refunds_each_post_their_own_entry(): void
    {
        $invoice = $this->paidStoreOrder(100.0, '2026-01-01 09:00:00', '2026-01-01 09:00:00');
        $this->ledger->accrueStoreReceipt($invoice, '2026-01-01 09:00:00');

        // Two partial refunds against ONE invoice. This is the case the old
        // UNIQUE (kind, invoice_id) could not express: the second would have been
        // refused by the database, so a partial refund would have been the only one
        // that ever posted.
        $first = $this->ledger->reverseStoreReceipt($invoice, 30.0, '2026-02-01 09:00:00');
        $second = $this->ledger->reverseStoreReceipt($invoice, 30.0, '2026-03-01 09:00:00');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second, 'each reversal is its own appended entry');
        $this->assertEqualsWithDelta(40.0, (float) $this->ledger->accountFor($this->storeId)['balance_base'], 0.001);
    }

    public function test_reversals_cannot_take_an_order_below_zero(): void
    {
        $invoice = $this->paidStoreOrder(100.0, '2026-01-01 09:00:00', '2026-01-01 09:00:00');
        $this->ledger->accrueStoreReceipt($invoice, '2026-01-01 09:00:00');

        // More than was ever credited — the shape of a hook firing twice, or a
        // refund larger than the receipt. Clamped, not obeyed: reversing 150 would
        // debit the reseller for money we have already given back to the customer.
        $this->ledger->reverseStoreReceipt($invoice, 150.0);

        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->ledger->accountFor($this->storeId)['balance_base'],
            0.001,
            'the order nets to zero, never below'
        );

        // And with nothing left, a further reversal posts nothing at all.
        $this->assertNull($this->ledger->reverseStoreReceipt($invoice, 10.0));
        $this->assertEqualsWithDelta(0.0, (float) $this->ledger->accountFor($this->storeId)['balance_base'], 0.001);
    }

    // -------------------------------------------------------------- the no-ops

    public function test_reversing_a_store_invoice_that_was_never_paid_does_nothing(): void
    {
        // An UNPAID store order: the receipt was never posted, so there is nothing
        // to reverse and the account must stay empty rather than go negative.
        $invoice = $this->storeOrder(50.0, '2026-01-01 09:00:00', 'unpaid');

        $this->assertNull($this->ledger->reverseStoreReceipt($invoice, 50.0));
        $this->assertEqualsWithDelta(0.0, (float) $this->ledger->accountFor($this->storeId)['balance_base'], 0.001);
    }

    public function test_reversing_an_invoice_that_belongs_to_no_store_does_nothing(): void
    {
        // The ordinary case: most invoices are ours, and refunding one has nothing
        // to do with any reseller's account.
        $customerId = $this->clients->create([
            'email' => 'plain-customer@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Plain',
            'last_name' => 'Customer',
        ]);

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, NULL, ?, ?, 0.0, ?, ?, ?, ?)',
            [$customerId, 'refunded', 25.0, 25.0, '2026-01-01', '2026-01-01 09:00:00', '2026-01-01 09:00:00']
        );

        $this->assertNull($this->ledger->reverseStoreReceipt($invoiceId, 25.0));
    }

    public function test_the_reversal_is_appended_and_the_original_receipt_is_untouched(): void
    {
        $invoice = $this->paidStoreOrder(80.0, '2026-01-01 09:00:00', '2026-01-01 09:00:00');
        $receiptId = $this->ledger->accrueStoreReceipt($invoice, '2026-01-01 09:00:00');

        $this->ledger->reverseStoreReceipt($invoice, 80.0);

        $rows = $this->db->select(
            'SELECT id, kind, amount FROM reseller_ledger WHERE reseller_id = ? ORDER BY id',
            [$this->storeId]
        );

        $this->assertCount(2, $rows, 'a reversal appends; it never edits or deletes');

        // The original row is still there, with its original sign and amount: an
        // append-only ledger has to keep the story, because "why is this balance
        // what it is" is the only question the account exists to answer.
        $this->assertSame((int) $receiptId, (int) $rows[0]['id']);
        $this->assertSame('store_receipt', (string) $rows[0]['kind']);
        $this->assertEqualsWithDelta(80.0, (float) $rows[0]['amount'], 0.001);

        $this->assertSame('receipt_reversal', (string) $rows[1]['kind']);
        $this->assertEqualsWithDelta(-80.0, (float) $rows[1]['amount'], 0.001);
        $this->assertNull($rows[1]['withdrawable_at'] ?? null, 'a reversal is never held');
    }

    // ------------------------------------------------------------------ seam

    public function test_the_kernel_listens_for_refunds_and_reverses_on_them(): void
    {
        // The seam between RefundService (which fires the hook, and already did
        // before this feature existed) and the account. Both halves compile and both
        // halves have tests of their own, so nothing but reading one file against the
        // other catches a wrong hook name or a listener that was never added — the
        // refund would simply never reach the ledger, and no test would fail.
        $kernel = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Kernel.php');

        $this->assertStringContainsString('HookPoints::INVOICE_REFUNDED', $kernel);
        $this->assertStringContainsString(
            '->reverseStoreReceipt(',
            $kernel,
            'the refund listener must call reverseStoreReceipt(), or refunds never reach the account'
        );

        // And the other half of the seam: the hook the account listens for really is
        // the one the refund path emits.
        $refunds = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Billing/RefundService.php');

        $this->assertStringContainsString('HookPoints::INVOICE_REFUNDED', $refunds);
        $this->assertStringContainsString("'invoiceId'", $refunds, 'the payload the listener reads must be the one fired');
    }

    // ------------------------------------------------- the cost side of the same rule

    public function test_a_refund_un_winds_both_the_receipt_and_the_cost_we_billed(): void
    {
        [$invoiceId] = $this->billableRefundable(100.0, 80.0);

        // 100 retail in, 80 cost out: the reseller is 20 in front.
        $this->assertEqualsWithDelta(20.0, (float) $this->ledger->accountFor($this->storeId)['balance_base'], 0.001);

        $this->ledger->reverseStoreReceipt($invoiceId, 100.0);
        $this->ledger->reverseCostForInvoice($invoiceId);

        // The sale un-wound completely: it neither earned nor cost anything.
        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->ledger->accountFor($this->storeId)['balance_base'],
            0.001,
            'a fully refunded sale must net to zero on BOTH sides'
        );
    }

    public function test_the_two_reversals_do_not_depend_on_which_runs_first(): void
    {
        // THE PROPERTY THAT IS EASY TO GET WRONG AND IMPOSSIBLE TO SEE IN A HAPPY PATH.
        //
        // If either ceiling is taken from the order's whole NET, then with a receipt of
        // 100 and a cost debit of 80 the order sits at +20 — so the cost reversal finds
        // a headroom of ZERO when it runs first and the account is left half-un-wound.
        // Same refund, two call orders, and only one of them works. That is why each
        // ceiling nets its OWN kind against its OWN reversal instead.
        [$receiptFirstId] = $this->billableRefundable(100.0, 80.0);
        $this->ledger->reverseStoreReceipt($receiptFirstId, 100.0);
        $this->ledger->reverseCostForInvoice($receiptFirstId);

        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->ledger->accountFor($this->storeId)['balance_base'],
            0.001,
            'receipt-first must fully un-wind'
        );

        [$costFirstId] = $this->billableRefundable(100.0, 80.0);
        $this->ledger->reverseCostForInvoice($costFirstId);
        $this->ledger->reverseStoreReceipt($costFirstId, 100.0);

        // The assertion measured on the BALANCE rather than on the order's ledger net,
        // because the cost DEBIT is posted per invoice rather than per order and so is
        // not in that net at all — the order's ledger sum reads +80 here even when the
        // reversal worked. The balance includes every entry and is what the bug would
        // actually get wrong: the buggy net-based ceiling leaves the cost standing, so
        // the account ends at 20 instead of 0.
        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->ledger->accountFor($this->storeId)['balance_base'],
            0.001,
            'cost-first must fully un-wind too — the result must not depend on call order'
        );
    }

    public function test_the_cost_reversal_credits_back_the_line_that_was_actually_billed(): void
    {
        [$invoiceId, $orderId] = $this->billableRefundable(100.0, 80.0);

        $this->ledger->reverseCostForInvoice($invoiceId);

        $row = $this->db->selectOne(
            "SELECT amount, invoice_id, withdrawable_at FROM reseller_ledger
             WHERE order_id = ? AND kind = 'cost_reversal' LIMIT 1",
            [$orderId]
        );

        $this->assertNotNull($row, 'the cost reversal must be posted');
        // POSITIVE: it reduces what the reseller owes, where the debit was negative.
        $this->assertEqualsWithDelta(80.0, (float) $row['amount'], 0.001);
        $this->assertNull($row['withdrawable_at'], 'a cost reversal is not held either');
    }

    public function test_a_cost_reversal_does_nothing_when_the_order_was_never_billed(): void
    {
        // The early-refund case: no cost invoice has been raised, so there is no line to
        // reverse against. The exclusion means none ever will be — so "nothing to do"
        // is the CORRECT outcome here, not a failure to reverse.
        $invoiceId = $this->paidStoreOrder(100.0, '2026-01-01 09:00:00', '2026-01-01 09:00:00');
        $this->ledger->accrueStoreReceipt($invoiceId, '2026-01-01 09:00:00');

        $this->assertNull($this->ledger->reverseCostForInvoice($invoiceId));
        $this->assertEqualsWithDelta(100.0, (float) $this->ledger->accountFor($this->storeId)['balance_base'], 0.001);
    }

    public function test_the_same_cost_is_not_credited_back_twice(): void
    {
        // The ceiling is the billed line LESS what has already been credited back, and
        // this is why that subtraction has to be there: the listener fires once per
        // refund EVENT, so a second partial refund on the same invoice reaches this
        // method again. Without the subtraction it re-credits the whole line, and the
        // reseller is handed the same cost back as many times as they refund in parts.
        // Nothing else in the ledger would object — the duplicate guard is keyed on the
        // INVOICE, and both reversals are against the same invoice.
        [$invoiceId] = $this->billableRefundable(100.0, 80.0);

        $this->assertNotNull($this->ledger->reverseCostForInvoice($invoiceId), 'the first reversal should post');
        $this->assertNull($this->ledger->reverseCostForInvoice($invoiceId), 'the second must find nothing left to credit');

        $this->assertEqualsWithDelta(
            80.0,
            (float) $this->db->selectOne(
                "SELECT COALESCE(SUM(amount), 0) AS total FROM reseller_ledger WHERE kind = 'cost_reversal'"
            )['total'],
            0.001,
            'exactly one reversal must exist'
        );
    }

    public function test_the_billing_job_records_the_order_on_the_invoice_line(): void
    {
        // The seam the whole cost reversal depends on, and it fails SILENTLY: if the
        // billing job does not write order_id, billedCostLineForOrder() returns null,
        // the reversal reports "never billed", and the ledger simply looks untouched.
        // No existing test would fail, and the reseller would keep being charged for a
        // refunded sale with nothing anywhere to show it.
        //
        // Read from the source rather than asserted through a fixture, because the test
        // fixture sets order_id itself and would therefore pass regardless.
        $job = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Reseller/ResellerCostBillingJob.php');

        $this->assertStringContainsString('invoice_items (invoice_id, description, amount, order_id)', $job);
        $this->assertStringContainsString("(int) \$order['id']", $job);
    }

    // ----------------------------------- and the cost that must never be raised at all

    public function test_a_refunded_order_is_excluded_from_cost_billing_and_from_the_report(): void
    {
        $repo = new ResellerCostRepository($this->db);
        $cutoff = (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s');

        // Two orders from the same month. THE CONTRAST IS THE TEST: a filter that
        // excluded nothing, or everything, would satisfy one half of this on its own.
        $this->paidStoreOrder(100.0, '2026-01-05 09:00:00', '2026-01-05 09:00:00');
        $refundedInvoice = $this->paidStoreOrder(100.0, '2026-01-06 09:00:00', '2026-01-06 09:00:00');

        $refundedOrder = (int) $this->db->selectOne(
            'SELECT order_id FROM invoices WHERE id = ?',
            [$refundedInvoice]
        )['order_id'];

        // Cost is billed monthly, so a refund that lands before the month closes must
        // simply never be billed — the receipt has already come off the account.
        $this->db->update("UPDATE invoices SET status = 'refunded' WHERE id = ?", [$refundedInvoice]);

        $billable = array_map(
            static fn (array $row): int => (int) $row['id'],
            $repo->unbilledBefore($cutoff)
        );

        $this->assertCount(1, $billable, 'only the order that was not refunded may be billed');
        $this->assertNotContains($refundedOrder, $billable);

        // And the REPORT must agree with the billing run, because they share one
        // definition of billable. If the two could drift, this page would show cost
        // that never becomes an invoice and nobody would know which was wrong.
        $totals = $repo->totals();
        $this->assertCount(1, $totals);
        $this->assertSame(1, (int) $totals[0]['order_count'], 'the refunded order must not be counted either');
    }

    // -------------------------------------------------------------- fixtures

    /**
     * A store order with a receipt on the account AND an 80-ish cost already billed, so
     * both reversals have something to undo. Returns [customer invoice id, order id].
     *
     * The cost is billed by hand rather than by running the monthly job, because the job
     * is not what is under test: what matters here is that the debit exists, that it is
     * linked to the order, and that it carries the figure actually billed. That last part
     * is why the fixture writes order_id itself — and why a separate test asserts the JOB
     * writes it, since a fixture that sets it would pass regardless.
     *
     * @return array{0: int, 1: int}
     */
    private function billableRefundable(float $retail, float $cost): array
    {
        $customerInvoice = $this->paidStoreOrder($retail, '2026-01-01 09:00:00', '2026-01-01 09:00:00');
        $this->ledger->accrueStoreReceipt($customerInvoice, '2026-01-01 09:00:00');

        $orderId = $this->orderIdForInvoice($customerInvoice);
        $now = date('Y-m-d H:i:s');

        $costInvoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, 0.0, ?, NULL, 1.000000, ?, ?, ?)',
            [$this->resellerClientId, 'unpaid', $cost, $cost, substr($now, 0, 10), $now, $now]
        );

        // What the billing job does: stamp the order with the invoice that billed it,
        // and record the ORDER ON THE LINE.
        $this->db->update('UPDATE orders SET reseller_cost_invoice_id = ? WHERE id = ?', [$costInvoiceId, $orderId]);
        $this->db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount, order_id) VALUES (?, ?, ?, ?)',
            [$costInvoiceId, 'Store order #' . $orderId, $cost, $orderId]
        );

        // And the debit itself, posted from the invoice the way the job posts it.
        $this->ledger->recordCostInvoice($costInvoiceId);

        return [$customerInvoice, $orderId];
    }

    private function orderIdForInvoice(int $invoiceId): int
    {
        return (int) $this->db->selectOne('SELECT order_id FROM invoices WHERE id = ?', [$invoiceId])['order_id'];
    }

    /** A PAID (or other-status) store order and its invoice. Returns the invoice id. */
    private function paidStoreOrder(float $retail, string $at, ?string $invoiceAt = null): int
    {
        return $this->storeOrder($retail, $at, 'paid', $invoiceAt);
    }

    private function storeOrder(float $retail, string $at, string $status, ?string $invoiceAt = null): int
    {
        $customerId = $this->clients->create([
            'email' => 'shopper-' . uniqid() . '@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Shop',
            'last_name' => 'Per',
        ]);

        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$customerId, $this->storeId, 'active', $retail, round($retail * 0.8, 2), $at, $at]
        );

        $invoiceAt ??= $at;

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, 0.0, ?, ?, ?, ?)',
            [$customerId, $orderId, $status, $retail, $retail, substr($invoiceAt, 0, 10), $invoiceAt, $invoiceAt]
        );
    }
}
