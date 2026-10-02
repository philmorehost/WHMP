<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * The production outage this guards against.
 *
 * Migration 0196 adds `invoices.parent_invoice_id` and the payable-facing queries
 * exclude rows where it is set (an invoice absorbed into a "Pay Selected Invoices"
 * consolidation is not independently payable). The migration runs from a boot step
 * whose failures are NOT fatal — so a site can be running the new CODE against a
 * schema that never got the new COLUMN, and it did:
 *
 *   Fatal error: Uncaught PDOException: SQLSTATE[42S22]: Column not found: 1054
 *   Unknown column 'i.parent_invoice_id' in 'where clause'
 *   ... InvoiceRepository.php(697) ... AdminDashboardController.php(73)
 *
 * The dashboard is the first casualty because overdueByCurrency() runs on it.
 *
 * Two properties are asserted, and they are different:
 *
 *  1. the queries must not THROW when the column is absent, and must still return
 *     the right figures — an install without the column has no consolidations, so
 *     nothing should be excluded;
 *  2. the repository repairs the column, so the full semantics come back without
 *     anyone running a migration by hand.
 *
 * Verified red against the previous implementation: it built the predicate
 * unconditionally as `parent_invoice_id IS NULL`, so every method below threw
 * "Unknown column".
 */
final class InvoiceMissingParentColumnTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);

        $this->clientId = $this->clients->create([
            'email' => 'legacy-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'first_name' => 'Leg',
            'last_name' => 'Acy',
        ]);
    }

    public function test_overdue_queries_survive_a_schema_without_the_column(): void
    {
        $this->insertOverdueInvoice(100.00);
        $this->dropParentColumn();

        // A fresh instance, so nothing can be cached from before the drop — the
        // same position a request lands in when the schema lags the code.
        $invoices = new InvoiceRepository($this->db);

        // Every method that fed the failing dashboard call.
        $this->assertCount(1, $invoices->overdue());
        $this->assertCount(1, $invoices->dueUnpaid());
        $this->assertCount(1, $invoices->unpaidIds());
        $this->assertSame(1, $invoices->countOverdue());
        $this->assertEqualsWithDelta(100.00, $invoices->sumOverdue(), 0.01);

        // The exact call in the stack trace, via the dashboard.
        $byCurrency = $invoices->overdueByCurrency();
        $this->assertCount(1, $byCurrency);
        $this->assertEqualsWithDelta(100.00, (float) $byCurrency[0]['amount'], 0.01);
        $this->assertSame(1, $byCurrency[0]['invoices']);
    }

    public function test_the_repository_restores_the_column_it_needs(): void
    {
        $this->dropParentColumn();

        $this->assertFalse($this->columnExists(), 'precondition: the column must be gone');

        // The read path establishes it, so the consolidation semantics are back
        // without anyone running a migration by hand.
        (new InvoiceRepository($this->db))->countOverdue();

        $this->assertTrue($this->columnExists(), 'the repository should repair the missing column');
    }

    /** With the column present the exclusion still applies, i.e. this did not weaken it. */
    public function test_a_consolidated_source_is_still_excluded_from_overdue(): void
    {
        $source = $this->insertOverdueInvoice(100.00);
        $consolidation = $this->insertOverdueInvoice(100.00);

        $invoices = new InvoiceRepository($this->db);
        $invoices->linkToParent($consolidation, [$source]);

        $this->assertSame(1, $invoices->countOverdue(), 'the absorbed source must not be counted twice');
        $this->assertEqualsWithDelta(100.00, $invoices->sumOverdue(), 0.01);
    }

    private function insertOverdueInvoice(float $total): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $yesterday = (new DateTimeImmutable('-1 day'))->format('Y-m-d');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, discount_amount, total, currency_id, currency_rate, due_date, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL, 1.0000, ?, ?, ?)',
            [$this->clientId, 'unpaid', $total, 0.0, 0.0, $total, $yesterday, $now, $now]
        );
    }

    /** Simulate an install whose migration 0196 never completed. */
    private function dropParentColumn(): void
    {
        foreach ([
            'ALTER TABLE invoices DROP FOREIGN KEY fk_invoices_parent',
            'ALTER TABLE invoices DROP INDEX idx_invoices_parent',
            'ALTER TABLE invoices DROP COLUMN parent_invoice_id',
        ] as $sql) {
            try {
                $this->db->statement($sql);
            } catch (\Throwable) {
                // The FK may own the index, so a later DROP can legitimately miss.
            }
        }

        $this->assertFalse($this->columnExists(), 'the fixture failed to remove the column');
    }

    private function columnExists(): bool
    {
        return $this->db->selectOne(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'parent_invoice_id'"
        ) !== null;
    }
}
