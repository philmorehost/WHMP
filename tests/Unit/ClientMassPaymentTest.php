<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\BillableItemRepository;
use CodeVault\Billing\ClientCreditRepository;
use CodeVault\Billing\ClientInvoiceController;
use CodeVault\Billing\CreditService;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\PaymentGatewayRepository;
use CodeVault\Billing\PaymentService;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Pdf\InvoicePdfBuilder;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;
use DateTimeImmutable;

/**
 * "Pay Selected Invoices" builds one consolidated Mass Payment invoice out of
 * the chosen unpaid invoices.
 *
 * The regression this file pins: the consolidated invoice copies each source
 * invoice's stored total verbatim, and those totals are *denominated* in the
 * client's currency (every invoice-raising path in this app uses
 * CurrencyService::denominateFor()). It used to be written with
 * lockColumns() instead, which stamps the client currency's live FX rate onto
 * the row — and every reader multiplies amounts by that column, so a
 * ₦28,339.00 invoice displayed and charged as ₦43,075,280.00. The client
 * reported this as the merged invoice showing the wrong amount for each line.
 */
final class ClientMassPaymentTest extends DatabaseTestCase
{
    private ClientInvoiceController $controller;
    private ClientRepository $clients;
    private CurrencyService $currency;
    private InvoiceRepository $invoices;
    private int $clientId;

    /** A non-default currency with a live rate well above 1, like NGN-on-USD. */
    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $currencies = new CurrencyRepository($this->db);
        $this->currency = new CurrencyService($currencies);

        $this->currencyId = $currencies->create('NGN', '₦', 1520.0000);

        $this->clientId = $this->clients->create([
            'email' => 'masspayer@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Mass',
            'last_name' => 'Payer',
            'currency_id' => $this->currencyId,
        ]);

        $configDir = sys_get_temp_dir() . '/codevault-mass-pay-test-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $guard = new ClientAuthGuard($session, $this->clients);
        // Seed the guard's own session key rather than calling login(), which
        // session_regenerate_id()s and warns in CLI (no active session). The
        // guard only ever reads `client_id`, so this is the same state.
        $_SESSION['client_id'] = $this->clientId;

        $container = new Container();
        $container->instance(SessionManager::class, $session);
        $container->instance(Database::class, $this->db);
        App::setContainer($container);

        $invoices = new InvoiceRepository($this->db);
        $this->invoices = $invoices;
        $transactions = new TransactionRepository($this->db);
        $settings = new SettingsRepository($this->db);
        $credit = new ClientCreditRepository($this->db);

        $this->controller = new ClientInvoiceController(
            $guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $invoices,
            $transactions,
            new PaymentGatewayRepository($this->db),
            $credit,
            new CreditService($credit, $invoices, $transactions, new PaymentService($invoices, $transactions, new HookDispatcher()), new HookDispatcher()),
            $this->currency,
            new InvoicePdfBuilder($this->currency, $settings),
            $settings,
            new BillableItemRepository($this->db)
        );
    }

    public function test_mass_payment_invoice_is_denominated_in_the_clients_currency_not_locked_to_the_live_rate(): void
    {
        $first = $this->unpaidInvoice(28339.00);
        $second = $this->unpaidInvoice(42008.40);

        $this->controller->massPay($this->massPayRequest([$first, $second]));

        $mass = $this->latestInvoice();

        $this->assertNotNull($mass);
        // Denominated, not locked: the amounts copied below are already in the
        // client's currency, so the rate column must stay at 1.0. A rate of
        // 1520 here is exactly the 1520x inflation the client saw.
        $this->assertSame($this->currencyId, (int) $mass['currency_id']);
        $this->assertSame(1.0, (float) $mass['currency_rate']);
        $this->assertSame(70347.40, (float) $mass['subtotal']);
        $this->assertSame(70347.40, (float) $mass['total']);
    }

    public function test_each_mass_payment_line_shows_its_own_source_invoice_total(): void
    {
        $first = $this->unpaidInvoice(28339.00);
        $second = $this->unpaidInvoice(42008.40);

        $this->controller->massPay($this->massPayRequest([$first, $second]));

        $mass = $this->latestInvoice();
        $items = $this->db->select('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id', [(int) $mass['id']]);

        $this->assertCount(2, $items);

        $clientCurrency = $this->currency->resolveForClient($this->clients->find($this->clientId));
        $shown = [];

        foreach ($items as $item) {
            // What the invoice page and the gateway both read: the stored
            // amount re-expressed through the row's own locked rate.
            $shown[] = $this->currency->formatDocument(
                (float) $item['amount'],
                $mass['currency_id'] !== null ? (int) $mass['currency_id'] : null,
                (float) $mass['currency_rate'],
                $clientCurrency
            );
        }

        sort($shown);
        $this->assertSame(['₦28,339.00', '₦42,008.40'], $shown);

        // And the line descriptions still name the invoices they came from.
        $descriptions = array_column($items, 'description');
        $this->assertContains("Mass Payment — Invoice #INV-{$first}", $descriptions);
        $this->assertContains("Mass Payment — Invoice #INV-{$second}", $descriptions);
    }

    public function test_the_line_amounts_add_up_to_the_invoice_total(): void
    {
        $first = $this->unpaidInvoice(28339.00);
        $second = $this->unpaidInvoice(42008.40);

        $this->controller->massPay($this->massPayRequest([$first, $second]));

        $mass = $this->latestInvoice();
        $sum = (float) $this->db->selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS s FROM invoice_items WHERE invoice_id = ?',
            [(int) $mass['id']]
        )['s'];

        $this->assertSame((float) $mass['subtotal'], round($sum, 2));
        $this->assertSame((float) $mass['total'], round($sum, 2));
    }

    public function test_selecting_a_single_invoice_still_redirects_without_creating_anything(): void
    {
        $only = $this->unpaidInvoice(28339.00);

        $this->controller->massPay($this->massPayRequest([$only]));

        $this->assertSame(1, (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM invoices WHERE client_id = ?',
            [$this->clientId]
        )['c']);
    }

    // ------------------------------- the consolidation LINK and the payment cascade

    public function test_creating_a_consolidation_records_which_invoices_it_absorbed(): void
    {
        // THE HALF THAT WAS MISSING. The consolidation copied each source's AMOUNT and
        // recorded nothing about which invoice it came from, so nothing could ever find
        // them again — and no payment path can cascade without the identity.
        $first = $this->unpaidInvoice(100.00);
        $second = $this->unpaidInvoice(50.00);

        $this->controller->massPay($this->massPayRequest([$first, $second]));

        $mass = $this->latestInvoice();

        $this->assertNotNull($mass);
        $this->assertNull($mass['parent_invoice_id'], 'the consolidation stands on its own');

        foreach ([$first, $second] as $id) {
            $this->assertSame(
                (int) $mass['id'],
                (int) $this->invoice($id)['parent_invoice_id'],
                'each absorbed invoice must point at the consolidation that absorbed it'
            );
        }
    }

    public function test_paying_the_consolidation_settles_every_invoice_it_absorbed(): void
    {
        $first = $this->unpaidInvoice(100.00);
        $second = $this->unpaidInvoice(50.00);

        $this->controller->massPay($this->massPayRequest([$first, $second]));
        $mass = $this->latestInvoice();

        // Both sources are now overdue, which is what makes the assertion below mean
        // something: with due dates in the future countOverdue() would be 0 either way
        // and the test would pass on a broken cascade.
        $this->makeOverdue([$first, $second, (int) $mass['id']]);

        // Before it is paid, the CONSOLIDATION is the debt and the invoices inside it
        // are not counted separately — otherwise the same money is overdue twice.
        $this->assertSame(1, $this->invoices->countOverdue());

        // Now pay it, and run the cascade the Kernel's INVOICE_PAID listener runs —
        // the same method, with the same argument it passes.
        $this->invoices->markPaid((int) $mass['id']);

        $paidAt = (string) $this->invoice((int) $mass['id'])['paid_at'];
        $settled = $this->invoices->settleChildren((int) $mass['id'], $paidAt);

        $this->assertSame(2, $settled, 'both absorbed invoices must be settled');
        $this->assertSame('paid', $this->invoice($first)['status']);
        $this->assertSame('paid', $this->invoice($second)['status']);

        // No invoice in the set is overdue any more: the customer paid once and owes
        // nothing. Before the cascade they owed the whole amount a second time, and the
        // dunning sweep would have chased them for it.
        $this->assertSame(0, $this->invoices->countOverdue());
        $this->assertEqualsWithDelta(0.0, $this->invoices->sumOverdue(), 0.001);
    }

    public function test_settling_a_consolidation_twice_neither_errors_nor_resurrects_a_voided_invoice(): void
    {
        // The payment path is deliberately idempotent against a duplicate gateway
        // delivery (PaymentService::recordPayment swallows it), so the cascade must be
        // too.
        //
        // ASSERTING THE ROW COUNT IS NOT ENOUGH, which is how the first version of this
        // test failed to catch a removed guard: MySQL reports 0 CHANGED rows when an
        // UPDATE matches but rewrites identical values, so an unguarded re-run also
        // returns 0 and looks correct. The guard's real job shows up only on a child
        // that has moved to another state since — a refund, say — which an unguarded
        // UPDATE drags back to `paid`, silently turning a refund into a payment and
        // emptying the refund record of meaning.
        $first = $this->unpaidInvoice(100.00);
        $second = $this->unpaidInvoice(50.00);

        $this->controller->massPay($this->massPayRequest([$first, $second]));
        $mass = $this->latestInvoice();

        $this->invoices->markPaid((int) $mass['id']);
        $paidAt = (string) $this->invoice((int) $mass['id'])['paid_at'];

        $this->assertSame(2, $this->invoices->settleChildren((int) $mass['id'], $paidAt));

        $this->invoices->markRefunded($second);

        $this->assertSame(0, $this->invoices->settleChildren((int) $mass['id'], $paidAt), 'a repeat settles nothing');
        $this->assertSame('refunded', $this->invoice($second)['status'], 'a repeat must not resurrect a refunded invoice');
        $this->assertSame('paid', $this->invoice($first)['status'], 'and the still-valid child is untouched');
        $this->assertSame($paidAt, (string) $this->invoice($first)['paid_at'], 'paid_at is not rewritten on a repeat');
    }

    public function test_cancelling_a_consolidation_gives_its_invoices_their_own_debt_back(): void
    {
        // The mirror image, and the worse bug: cancelling one payment demand must not
        // leave several real debts looking settled.
        $first = $this->unpaidInvoice(100.00);
        $second = $this->unpaidInvoice(50.00);

        $this->controller->massPay($this->massPayRequest([$first, $second]));
        $mass = $this->latestInvoice();

        $this->invoices->markPaid((int) $mass['id']);
        $paidAt = (string) $this->invoice((int) $mass['id'])['paid_at'];
        $this->invoices->settleChildren((int) $mass['id'], $paidAt);
        $this->assertSame('paid', $this->invoice($first)['status'], 'precondition: the cascade ran');

        $this->invoices->markCancelled((int) $mass['id']);

        foreach ([$first, $second] as $id) {
            $this->assertSame('unpaid', $this->invoice($id)['status'], 'a cancelled consolidation leaves the debt with its source invoices');
            $this->assertNull($this->invoice($id)['paid_at']);
            $this->assertNull($this->invoice($id)['parent_invoice_id'], 'and each invoice stands on its own again');
        }
    }

    public function test_an_invoice_already_absorbed_is_not_absorbed_a_second_time(): void
    {
        // The form is client-supplied, so this is defended in the controller as well as
        // the view: absorbing a source twice would ask the customer to pay it twice,
        // which is the original bug wearing a different hat.
        $first = $this->unpaidInvoice(100.00);
        $second = $this->unpaidInvoice(50.00);

        $this->controller->massPay($this->massPayRequest([$first, $second]));
        $mass = $this->latestInvoice();

        $this->controller->massPay($this->massPayRequest([$first, $second]));

        $this->assertSame(
            (int) $mass['id'],
            (int) $this->invoice($first)['parent_invoice_id'],
            'the link must still point at the ORIGINAL consolidation'
        );

        $this->assertSame(3, (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM invoices WHERE client_id = ?',
            [$this->clientId]
        )['c'], 'no second consolidation may be created from already-absorbed invoices');
    }

    // ---------------------------------------------- the seam the cascade hangs off

    public function test_the_kernel_settles_a_consolidations_invoices_when_it_is_paid(): void
    {
        // Two halves that each look correct alone: ClientInvoiceController records the
        // link, InvoiceRepository::settleChildren does the settling, and nothing but
        // reading one file against the other proves they are joined. A listener that was
        // never registered means paying a consolidation settles NOTHING and fails
        // silently — which is precisely the bug being fixed here.
        $kernel = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Kernel.php');

        $this->assertStringContainsString('HookPoints::INVOICE_PAID', $kernel);
        $this->assertStringContainsString(
            '->settleChildren(',
            $kernel,
            'the INVOICE_PAID listener must call settleChildren(), or paying a consolidation settles nothing'
        );

        // And the link really is written on the path this test drives, by the method
        // the Kernel listener reverses.
        $this->assertStringContainsString('linkToParent', (string) file_get_contents(dirname(__DIR__, 2) . '/core/Billing/ClientInvoiceController.php'));
    }

    /** @return array<string, mixed> */
    private function invoice(int $id): array
    {
        $row = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$id]);

        $this->assertNotNull($row, "invoice {$id} must exist");

        return $row;
    }

    /** @param array<int, int> $ids */
    private function makeOverdue(array $ids): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $this->db->update(
            "UPDATE invoices SET due_date = '2000-01-01' WHERE id IN ({$placeholders})",
            $ids
        );
    }

    /** @param array<int, int> $ids */
    private function massPayRequest(array $ids): Request
    {
        return new Request([], ['invoice_ids' => array_map('strval', $ids)], ['REQUEST_METHOD' => 'POST'], []);
    }

    /** An unpaid invoice denominated in the client's currency — rate 1.0. */
    private function unpaidInvoice(float $total): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, discount_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'unpaid', $total, 0.0, 0.0, $total, $this->currencyId, 1.0000, substr($now, 0, 10), $now, $now]
        );
    }

    /** @return array<string, mixed>|null */
    private function latestInvoice(): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM invoices WHERE client_id = ? ORDER BY id DESC LIMIT 1',
            [$this->clientId]
        );
    }
}
