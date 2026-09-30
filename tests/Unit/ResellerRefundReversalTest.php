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

    // -------------------------------------------------------------- fixtures

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
