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
use CodeVault\Reseller\ResellerPayoutRepository;
use CodeVault\Reseller\ResellerPayoutService;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\RoleRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Payout requests: who may ask for what, and what an answer does to the account.
 *
 * Phase B moves no money — payment is a manual bank transfer an admin records —
 * so the properties worth defending are all about the ACCOUNT'S arithmetic being
 * impossible to cheat:
 *
 *  - **Only matured money can be requested.** A request draws on the withdrawable
 *    figure, not the balance, so a receipt still inside the holding period cannot
 *    be paid out early.
 *  - **A pending request cannot be spent twice.** The account is debited when the
 *    request is MADE, so a second request fails on arithmetic rather than on a
 *    rule. The database enforces it independently, and that is tested by bypassing
 *    the service entirely and watching the constraint refuse.
 *  - **A refusal returns the funds, and an approval does not take them twice.**
 *    The ledger entry happens once, at request time.
 *  - **A payout history is auditable.** A payment cannot be recorded without a
 *    reference tying it to a bank line.
 *
 * The last one is not bookkeeping pedantry: "we paid them" with no reference is an
 * assertion, and the only thing that makes it a fact is a line on a statement.
 */
final class ResellerPayoutTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private SettingsRepository $settings;
    private CurrencyService $currency;
    private ResellerLedgerRepository $ledger;
    private ResellerLedgerService $accounts;
    private ResellerPayoutRepository $payouts;
    private ResellerPayoutService $service;
    private int $resellerClientId;
    private int $otherResellerClientId;
    private int $customerId;
    private int $storeId;
    private int $otherStoreId;
    private int $ngnId;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $configDir = sys_get_temp_dir() . '/codevault-payout-' . uniqid();
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
        $this->accounts = new ResellerLedgerService(
            $this->ledger,
            $storeRepo,
            $this->clients,
            $this->currency,
            $this->settings
        );

        $this->payouts = new ResellerPayoutRepository($this->db);
        $this->service = new ResellerPayoutService(
            $this->payouts,
            $this->ledger,
            $this->accounts,
            $this->currency
        );

        $this->resellerClientId = $this->clients->create([
            'email' => 'store-owner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Store',
            'last_name' => 'Owner',
        ]);

        $this->otherResellerClientId = $this->clients->create([
            'email' => 'other-owner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Other',
            'last_name' => 'Owner',
        ]);

        $this->customerId = $this->clients->create([
            'email' => 'shopper@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Shopper',
            'last_name' => 'Person',
        ]);

        $this->storeId = (int) $stores->openForClient($this->resellerClientId, 'Acme')['store']['id'];
        $this->otherStoreId = (int) $stores->openForClient($this->otherResellerClientId, 'Other')['store']['id'];
        $this->ngnId = (new CurrencyRepository($this->db))->create('NGN', 'N', 1490.0);

        // A real admin row, because reseller_payouts.decided_by has a foreign key
        // to admins(id). Deciding a payout is done by an admin, so a test that
        // records a decision has to have one -- and inventing an id would be
        // testing a state the database refuses to represent.
        $roleId = (new RoleRepository($this->db))->create('Super Admin', true, []);
        $this->adminId = (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['root', 'root@example.test', password_hash('correct-horse-battery', PASSWORD_ARGON2ID), 'Root', $roleId, $this->now(), $this->now()]
        );
    }

    // ------------------------------------------------------------ eligibility

    public function test_a_matured_receipt_can_be_requested(): void
    {
        $this->earn(100.0, 60);

        $result = $this->service->request($this->storeId);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertEqualsWithDelta(100.0, (float) $result['payout']['amount_base'], 0.001);
        $this->assertSame('pending', (string) $result['payout']['status']);
        $this->assertSame('bank_transfer', (string) $result['payout']['method']);
    }

    public function test_money_still_inside_the_holding_period_cannot_be_requested(): void
    {
        // Earned minutes ago, so it is owed but not yet withdrawable. This is the
        // whole point of the holding period: a chargeback can still arrive.
        $this->earn(100.0, 0);

        $result = $this->service->request($this->storeId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Nothing is available to withdraw', (string) $result['error']);
        $this->assertSame(0, $this->payoutRowCount());
    }

    public function test_a_request_below_the_minimum_is_refused_but_the_funds_are_kept(): void
    {
        $this->settings->set('reseller.payout_minimum', '50.00');
        $this->earn(20.0, 60);

        $result = $this->service->request($this->storeId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('minimum payout', (string) $result['error']);
        $this->assertSame(0, $this->payoutRowCount());

        // The money is still theirs — a refusal must not quietly confiscate a
        // balance that is merely too small to transfer.
        $this->assertEqualsWithDelta(20.0, $this->ledger->balance($this->storeId), 0.001);
        $this->assertEqualsWithDelta(20.0, $this->ledger->withdrawableBalance($this->storeId, $this->now()), 0.001);
    }

    public function test_exactly_the_minimum_is_enough(): void
    {
        $this->settings->set('reseller.payout_minimum', '50.00');
        $this->earn(50.0, 60);

        // The boundary, from the side that must be allowed. A refusal tested only
        // on the refusing side can pass for altogether the wrong reason.
        $this->assertTrue($this->service->request($this->storeId)['ok']);
    }

    // ------------------------------------------------------- double spending

    public function test_the_request_debits_the_account_so_the_money_cannot_be_requested_twice(): void
    {
        $this->earn(100.0, 60);

        $this->assertTrue($this->service->request($this->storeId)['ok']);

        // The account is debited at request time, not at payment, so a second
        // request finds nothing withdrawable.
        $this->assertEqualsWithDelta(0.0, (float) $this->accounts->accountFor($this->storeId)['balance_base'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->accounts->accountFor($this->storeId)['withdrawable_base'], 0.001);

        $second = $this->service->request($this->storeId);

        $this->assertFalse($second['ok']);
        $this->assertSame(1, $this->payoutRowCount());
    }

    public function test_the_database_itself_refuses_a_second_pending_request(): void
    {
        // Bypasses the service entirely: no eligibility check, no ownership check,
        // nothing but the constraint. This is the difference between "we check" and
        // "it cannot happen" — the service also checks, but a check can be
        // forgotten in a code path nobody has written yet.
        $this->insertPending($this->storeId, 10.0);

        $threw = null;

        try {
            $this->insertPending($this->storeId, 10.0);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw, 'the database allowed two pending requests for one reseller');
        // Naming the constraint proves WHICH rule refused it, rather than any
        // failure at all counting as success.
        $this->assertStringContainsString('uq_reseller_payouts_open', $threw->getMessage());
    }

    public function test_a_second_reseller_is_unaffected_by_the_first_ones_open_request(): void
    {
        // The uniqueness is per reseller. A constraint that accidentally spanned
        // all resellers would pass the test above and block the whole platform
        // after one request.
        $this->earn(100.0, 60);
        $this->earn(100.0, 60, $this->otherStoreId);

        $this->assertTrue($this->service->request($this->storeId)['ok']);
        $this->assertTrue($this->service->request($this->otherStoreId)['ok']);
        $this->assertSame(2, $this->payoutRowCount());
    }

    // ------------------------------------------------------------- decisions

    public function test_recording_a_payment_requires_a_reference(): void
    {
        $this->earn(100.0, 60);
        $id = (int) $this->service->request($this->storeId)['payout']['id'];

        $result = $this->service->markPaid($id, '   ', $this->adminId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('reference is required', (string) $result['error']);
        $this->assertSame('pending', (string) $this->payouts->find($id)['status'], 'a refused payment must leave the request open');

        // And with one, it goes through and the reference is kept.
        $this->assertTrue($this->service->markPaid($id, 'TRF-99120', $this->adminId)['ok']);
        $paid = $this->payouts->find($id);
        $this->assertSame('paid', (string) $paid['status']);
        $this->assertSame('TRF-99120', (string) $paid['reference']);
        $this->assertSame($this->adminId, (int) $paid['decided_by']);
    }

    public function test_recording_a_payment_does_not_debit_the_account_a_second_time(): void
    {
        $this->earn(100.0, 60);
        $id = (int) $this->service->request($this->storeId)['payout']['id'];
        $afterRequest = $this->ledger->balance($this->storeId);

        $this->service->markPaid($id, 'TRF-1', $this->adminId);

        // The debit happened at request time. Debiting again on payment would take
        // the money twice, which is the single most expensive mistake this file
        // could make.
        $this->assertEqualsWithDelta($afterRequest, $this->ledger->balance($this->storeId), 0.001);
        // Two entries: the receipt, and the single debit taken at request time.
        $this->assertSame(2, $this->ledgerEntryCount(), 'the receipt plus ONE debit -- not a second one on payment');
    }

    public function test_rejecting_returns_the_funds_and_leaves_both_facts_in_the_record(): void
    {
        $this->earn(100.0, 60);
        $id = (int) $this->service->request($this->storeId)['payout']['id'];

        $result = $this->service->reject($id, 'Bank details could not be verified.', $this->adminId);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('rejected', (string) $this->payouts->find($id)['status']);

        // The balance is whole again...
        $this->assertEqualsWithDelta(100.0, $this->ledger->balance($this->storeId), 0.001);

        // ...and immediately withdrawable, because these funds had already cleared
        // the holding period when they were requested.
        $this->assertEqualsWithDelta(
            100.0,
            $this->ledger->withdrawableBalance($this->storeId, $this->now()),
            0.001
        );

        // Two entries, not one: the request happened, and it was refused. An
        // append-only account has to keep the story.
        $this->assertSame(3, $this->ledgerEntryCount(), 'receipt + request + reversal');
        $kinds = array_column($this->ledger->entries($this->storeId), 'kind');
        $this->assertContains('adjustment', $kinds);
        $this->assertContains('payout', $kinds);
    }

    public function test_the_funds_can_be_requested_again_after_a_rejection(): void
    {
        $this->earn(100.0, 60);
        $first = (int) $this->service->request($this->storeId)['payout']['id'];
        $this->service->reject($first, 'nope', $this->adminId);

        // The returned money must be genuinely spendable, not merely visible: a
        // reversal that restores the balance but leaves the account un-requestable
        // would be the worst of both.
        $second = $this->service->request($this->storeId);

        $this->assertTrue($second['ok'], (string) $second['error']);
        $this->assertEqualsWithDelta(100.0, (float) $second['payout']['amount_base'], 0.001);
    }

    public function test_a_request_can_only_be_decided_once(): void
    {
        $this->earn(100.0, 60);
        $id = (int) $this->service->request($this->storeId)['payout']['id'];

        $this->assertTrue($this->service->markPaid($id, 'TRF-1', $this->adminId)['ok']);

        $again = $this->service->markPaid($id, 'TRF-2', $this->adminId);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already', (string) $again['error']);

        // And the first decision is intact — a second attempt must not overwrite it.
        $this->assertSame('TRF-1', (string) $this->payouts->find($id)['reference']);

        // A rejection arriving after payment must not return the funds.
        $this->assertFalse($this->service->reject($id, 'too late', $this->adminId)['ok']);
        $this->assertEqualsWithDelta(0.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_a_reseller_cannot_cancel_another_resellers_request(): void
    {
        $this->earn(100.0, 60, $this->otherStoreId);
        $id = (int) $this->service->request($this->otherStoreId)['payout']['id'];

        $result = $this->service->cancel($id, $this->storeId);

        $this->assertFalse($result['ok']);
        $this->assertSame('pending', (string) $this->payouts->find($id)['status']);
    }

    public function test_a_reseller_can_cancel_their_own_request_and_get_the_money_back(): void
    {
        $this->earn(100.0, 60);
        $id = (int) $this->service->request($this->storeId)['payout']['id'];

        $result = $this->service->cancel($id, $this->storeId);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('cancelled', (string) $this->payouts->find($id)['status']);
        $this->assertEqualsWithDelta(100.0, $this->ledger->balance($this->storeId), 0.001);
    }

    // ------------------------------------------------------------------- FX

    public function test_the_rate_is_locked_at_the_request_and_the_payout_records_it(): void
    {
        $this->db->update('UPDATE clients SET currency_id = ? WHERE id = ?', [$this->ngnId, $this->resellerClientId]);
        $this->earn(100.0, 60);

        $payout = $this->service->request($this->storeId)['payout'];

        // 100 base becomes 149,000 NGN at the locked rate, and BOTH the rate and
        // both figures are stored. Re-deriving the sent amount from a later rate
        // would make a settled payout disagree with the transfer that settled it.
        $this->assertSame($this->ngnId, (int) $payout['currency_id']);
        $this->assertEqualsWithDelta(1490.0, (float) $payout['currency_rate'], 0.000001);
        $this->assertEqualsWithDelta(149000.0, (float) $payout['amount'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $payout['amount_base'], 0.001);

        // Then the rate moves. The recorded payout must not.
        $this->db->update('UPDATE currencies SET exchange_rate = ? WHERE id = ?', [2000.0, $this->ngnId]);

        $reloaded = $this->payouts->find((int) $payout['id']);
        $this->assertEqualsWithDelta(1490.0, (float) $reloaded['currency_rate'], 0.000001);
        $this->assertEqualsWithDelta(149000.0, (float) $reloaded['amount'], 0.01);
    }

    public function test_the_request_draws_on_the_withdrawable_figure_not_the_balance(): void
    {
        // One matured receipt and one that is not. Only the matured one may be
        // requested, so the payout must be 100 and not 130 -- and the 30 left
        // behind must stay owed.
        $this->earn(100.0, 60);
        $this->earn(30.0, 0);

        $payout = $this->service->request($this->storeId)['payout'];

        $this->assertEqualsWithDelta(100.0, (float) $payout['amount_base'], 0.001);
        $this->assertEqualsWithDelta(30.0, $this->ledger->balance($this->storeId), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->ledger->withdrawableBalance($this->storeId, $this->now()), 0.001);
    }

    // -------------------------------------------------------------- helpers

    /** Credit a matured (or not) store receipt by making one of its orders paid. */
    private function earn(float $retail, int $daysAgo, ?int $storeId = null): void
    {
        $storeId ??= $this->storeId;

        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $storeId, 'active', $retail, round($retail * 0.8, 2), '2026-01-01 09:00:00', '2026-01-01 09:00:00']
        );

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $orderId, 'paid', $retail, 0.0, $retail, '2026-01-01', '2026-01-01 09:00:00', '2026-01-01 09:00:00']
        );

        $this->accounts->accrueStoreReceipt(
            $invoiceId,
            $daysAgo === 0
                ? $this->now()
                : (new \DateTimeImmutable('-' . $daysAgo . ' days'))->format('Y-m-d H:i:s')
        );
    }

    /**
     * A pending payout inserted straight into the table, with no service involved.
     *
     * Used to prove the DATABASE refuses a second one. Going through the service
     * would prove only that the service checks, which is a weaker claim.
     */
    private function insertPending(int $storeId, float $amountBase): void
    {
        $this->db->insert(
            'INSERT INTO reseller_payouts
                (reseller_id, client_id, amount, currency_rate, amount_base, status, method, requested_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$storeId, $this->resellerClientId, $amountBase, 1.0, $amountBase, 'pending', 'bank_transfer', $this->now()]
        );
    }

    private function payoutRowCount(): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM reseller_payouts')['c'] ?? 0);
    }

    private function ledgerEntryCount(): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM reseller_ledger')['c'] ?? 0);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
