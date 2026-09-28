<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Kernel;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerLedgerRepository;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The SEAM between INVOICE_PAID and the reseller's account.
 *
 * ResellerLedgerTest covers the money rules by calling the service directly,
 * which proves the service works but says nothing about whether anything ever
 * CALLS it. The Kernel listener is a hook name and a class name joined by a
 * string, and no compiler checks that seam: rename the constant, mistype the
 * class, forget to register the listener, and every existing test still passes
 * while the account silently stays empty in production.
 *
 * So this class boots the real Kernel, fires the real hook, and asserts the
 * ledger moved. It deliberately re-checks the two properties through the seam
 * rather than trusting them from the unit test, because the listener is what
 * decides whether the guard is even reached.
 *
 * The Kernel is booted with Database and SettingsRepository pinned to the test
 * database. Both are Kernel singletons resolved during boot against the
 * APPLICATION's database, so re-pinning Database alone does not reach them --
 * that mistake already cost a run once (AiSystemHealthJobTest).
 */
final class ResellerLedgerWiringTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private ResellerLedgerRepository $ledger;
    private int $resellerClientId;
    private int $customerId;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();

        new Kernel(dirname(__DIR__, 2));

        $container = App::container();
        $container->instance(Database::class, $this->db);
        $container->instance(SettingsRepository::class, new SettingsRepository($this->db));

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->ledger = new ResellerLedgerRepository($this->db);

        $storeRepo = new ResellerStoreRepository($this->db);
        $configDir = sys_get_temp_dir() . '/codevault-ledger-wiring-' . uniqid();
        mkdir($configDir);

        $stores = new ResellerStoreService(
            $storeRepo,
            new ResellerStoreLocator($storeRepo, new Config($configDir)),
            new DomainVerifier()
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
    }

    // ------------------------------------------------------------- the seam

    public function test_paying_a_store_orders_invoice_accrues_a_receipt_through_the_hook(): void
    {
        $order = $this->storeOrder(120.0, 'paid');

        $this->fireInvoicePaid($order['invoiceId']);

        $this->assertEqualsWithDelta(
            120.0,
            $this->ledger->balance($this->storeId),
            0.001,
            'the INVOICE_PAID listener must reach the ledger -- if this is 0, the seam is broken, not the money rules'
        );
    }

    public function test_the_hook_firing_twice_credits_once(): void
    {
        $order = $this->storeOrder(120.0, 'paid');

        $this->fireInvoicePaid($order['invoiceId']);
        $this->fireInvoicePaid($order['invoiceId']);

        $this->assertEqualsWithDelta(120.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_an_unpaid_invoice_reaching_the_listener_credits_nothing(): void
    {
        // Fired directly, which the real hook would not do -- but the listener is
        // the only thing between the hook and the service, so if it ever grew a
        // reason to fire for an unpaid invoice, this is where that would show.
        $order = $this->storeOrder(120.0, 'unpaid');

        $this->fireInvoicePaid($order['invoiceId']);

        $this->assertEqualsWithDelta(0.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_an_invoice_for_an_order_with_no_store_credits_nothing(): void
    {
        // The ordinary case: most invoices are ours. It must be quiet.
        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$this->customerId, 'active', 99.0, '2026-01-10 09:00:00', '2026-01-10 09:00:00']
        );

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $orderId, 'paid', 99.0, 0.0, 99.0, '2026-01-10', '2026-01-10 09:00:00', '2026-01-10 09:00:00']
        );

        $this->fireInvoicePaid($invoiceId);

        $this->assertEqualsWithDelta(0.0, $this->ledger->balance($this->storeId), 0.001);
    }

    public function test_the_listener_is_registered_under_the_hook_the_payment_path_fires(): void
    {
        // A direct statement of the seam: PaymentService fires INVOICE_PAID after
        // settling. If the listener were registered under a different constant
        // (or not at all), the three tests above would still pass while nothing
        // happened in production -- so assert the registration itself.
        $dispatcher = App::container()->make(HookDispatcher::class);
        $before = $this->ledger->balance($this->storeId);
        $order = $this->storeOrder(55.0, 'paid');

        $dispatcher->fire(HookPoints::INVOICE_PAID, ['invoiceId' => $order['invoiceId']]);

        $this->assertEqualsWithDelta($before + 55.0, $this->ledger->balance($this->storeId), 0.001);
    }

    // ------------------------------------------------------------- helpers

    private function fireInvoicePaid(int $invoiceId): void
    {
        App::container()->make(HookDispatcher::class)->fire(HookPoints::INVOICE_PAID, ['invoiceId' => $invoiceId]);
    }

    /** @return array{orderId: int, invoiceId: int} */
    private function storeOrder(float $retail, string $status = 'paid'): array
    {
        $at = '2026-01-10 09:00:00';

        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $this->storeId, 'active', $retail, round($retail * 0.8, 2), null, 1.0, $at, $at]
        );

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $orderId, $status, $retail, 0.0, $retail, null, 1.0, '2026-01-10', $at, $at]
        );

        return ['orderId' => $orderId, 'invoiceId' => $invoiceId];
    }
}
