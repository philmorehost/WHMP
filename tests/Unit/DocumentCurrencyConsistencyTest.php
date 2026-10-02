<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\OrderRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Reports\ReportRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * ONE DOCUMENT, ONE SYMBOL — ON EVERY SURFACE.
 *
 * The rule (CurrencyService / the repositories): a document displays in the
 * currency it was locked to at creation (`currency_id`), and an UNLOCKED
 * document (`currency_id IS NULL`) — an imported row or one written before
 * currency locking existed — displays in the CLIENT's currency, only falling
 * back to the system default for a client who has no currency at all.
 *
 * WHY THIS TEST EXISTS. The rule is written out in several places: the COALESCE
 * chain in the repository SQL, and a `currency_id !== null ? … : …` ternary in
 * the controllers and PDF builders. When one of those drifts, the SAME stored
 * figure renders with a different symbol depending on which page you open —
 * which reads to a client as the amount having changed. That is a bug nobody
 * sees until two screens are put side by side, so this test asserts the
 * surfaces AGAINST EACH OTHER rather than each on its own.
 *
 * The specific drift worth guarding: resolving an unlocked document to the BASE
 * currency. It is the tempting "NULL means base" reading of the column, and it
 * labels a naira client's imported invoice "$7,501.50".
 *
 * Negative control (verified red): point any one of the assertions below at the
 * base/default currency instead of the client's — e.g. change the client's
 * fallback in InvoiceRepository::paginate() — and the matching assertion fails
 * with "expected NGN, got USD" while the others stay green, which is exactly
 * the page-disagreement symptom.
 */
final class DocumentCurrencyConsistencyTest extends DatabaseTestCase
{
    private CurrencyRepository $currencies;
    private CurrencyService $service;
    private ClientRepository $clients;
    private InvoiceRepository $invoices;
    private OrderRepository $orders;
    private ReportRepository $reports;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->currencies = new CurrencyRepository($this->db);
        $this->service = new CurrencyService($this->currencies);
        $this->clients = new ClientRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->orders = new OrderRepository($this->db);
        $this->reports = new ReportRepository($this->db);
    }

    /**
     * An unlocked invoice for a naira client must read as naira on the invoice
     * list, through the format helper the detail pages use, AND in the income
     * report that the dashboard and reports page read.
     */
    public function test_unlocked_invoice_reads_as_the_clients_currency_everywhere(): void
    {
        $naira = $this->currencies->create('NGN', '₦', 1490.0000);
        $clientId = $this->createClient($naira);
        $invoiceId = $this->insertInvoice($clientId, null, 7501.50, 'paid');

        // 1. The list query (InvoiceRepository::paginate → COALESCE chain).
        $listRow = $this->rowFor($this->invoices->paginate()['data'], $invoiceId);
        $this->assertSame('NGN', $listRow['currency_code'], 'The invoice list re-labelled an unlocked invoice.');
        $this->assertSame('₦', $listRow['currency_symbol']);

        // 2. The detail helpers the admin/client invoice pages use. A NULL
        //    currency_id must resolve to the client's currency, not the default.
        $clientCurrency = $this->service->resolveForClient($this->clients->find($clientId));
        $this->assertSame(
            '₦7,501.50',
            $this->service->formatDocument(7501.50, null, 1.0, $clientCurrency),
            'The invoice detail page re-labelled an unlocked invoice.'
        );

        // 3. The income report (ReportRepository::incomeByMonth → COALESCE chain).
        $month = $this->reports->incomeByMonth((int) (new DateTimeImmutable())->format('Y'));
        $bucketed = array_values(array_filter(
            $month,
            static fn (array $row): bool => (int) ($row['currency_id'] ?? 0) === $naira
        ));
        $this->assertCount(1, $bucketed, 'The income report bucketed naira money under another currency.');
    }

    /**
     * The same rule for orders, and the same rule for the ORDER list (a
     * different query from the invoice list, so it is a separate surface).
     */
    public function test_unlocked_order_reads_as_the_clients_currency_everywhere(): void
    {
        $naira = $this->currencies->create('NGN', '₦', 1490.0000);
        $clientId = $this->createClient($naira);
        $orderId = $this->insertOrder($clientId, null, 'active');

        $found = $this->orders->find($orderId);
        $this->assertSame('NGN', $found['currency_code']);
        $this->assertSame('₦', $found['currency_symbol']);

        $listed = $this->rowFor($this->orders->all(), $orderId);
        $this->assertSame('NGN', $listed['currency_code'], 'The order list re-labelled an unlocked order.');
    }

    /**
     * The rule's other half, which is the one that must NOT regress while
     * fixing the first: a document that DID lock a currency keeps it, even when
     * that differs from the client's current one. Otherwise "use the client's
     * currency" would silently re-price every historical order.
     */
    public function test_a_locked_currency_beats_the_clients_current_currency(): void
    {
        $naira = $this->currencies->create('NGN', '₦', 1490.0000);
        $eur = $this->currencies->create('EUR', '€', 0.9200);
        // Client is on naira now...
        $clientId = $this->createClient($naira);
        // ...but the order was locked to EUR at checkout.
        $orderId = $this->insertOrder($clientId, $eur, 'active');

        $found = $this->orders->find($orderId);

        $this->assertSame('EUR', $found['currency_code']);
        $this->assertSame('€', $found['currency_symbol']);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function rowFor(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        $this->fail('Row ' . $id . ' was not returned by the query.');
    }

    private function createClient(?int $currencyId): int
    {
        return $this->clients->create([
            'email' => 'consistency-' . uniqid() . '@example.test',
            'password' => 'whatever123',
            'first_name' => 'Con',
            'last_name' => 'Sistency',
            'currency_id' => $currencyId,
        ]);
    }

    private function insertInvoice(int $clientId, ?int $currencyId, float $total, string $status): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, discount_amount, total, currency_id, currency_rate, due_date, created_at, updated_at, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $status, $total, 0.0, 0.0, $total, $currencyId, 1.0000, $now, $now, $now, $status === 'paid' ? $now : null]
        );
    }

    private function insertOrder(int $clientId, ?int $currencyId, string $status): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, currency_id, currency_rate, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $status, 7501.50, $currencyId, 1.0000, $now, $now]
        );
    }
}
