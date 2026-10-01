<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\BillableItemInvoicingJob;
use CodeVault\Billing\BillableItemRepository;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The daily sweep that turns pending billable items into invoices.
 *
 * Two properties matter, and both are about the SAME charge never being billed
 * twice:
 *
 *  - **A billed item is marked billed**, so the next sweep skips it. `uninvoiced()`
 *    selects on exactly the column `markInvoiced()` writes, so that write is the
 *    entire guard.
 *  - **The invoice and the mark are atomic.** They are two statements, and the
 *    selection depends on the second one. A process that died in between would leave
 *    the item pending and the next sweep would raise a SECOND invoice for it,
 *    billing the client twice with nothing recording that it happened. The window is
 *    a crash rather than a logic error, so it is injected here rather than provoked
 *    through the job's own API.
 */
final class BillableItemInvoicingJobTest extends DatabaseTestCase
{
    private BillableItemRepository $items;
    private BillableItemInvoicingJob $job;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->items = new BillableItemRepository($this->db);
        $clients = new ClientRepository($this->db);
        $settings = new SettingsRepository($this->db);

        $this->job = new BillableItemInvoicingJob(
            $this->items,
            $clients,
            new TaxCalculator(new TaxRuleRepository($this->db), new VatNumberValidator(), new TaxSettings($settings)),
            new CurrencyService(new CurrencyRepository($this->db)),
            $this->db,
            new HookDispatcher()
        );

        $this->clientId = $clients->create([
            'email' => 'billable@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Bill',
            'last_name' => 'Able',
        ]);
    }

    public function test_a_pending_item_becomes_one_invoice_and_is_marked_billed(): void
    {
        $itemId = $this->items->create($this->clientId, 'Ticket #12 — extra work', 120.00);

        $this->job->handle();

        $invoice = $this->db->selectOne('SELECT * FROM invoices ORDER BY id DESC LIMIT 1');

        $this->assertNotNull($invoice, 'the item must have produced an invoice');
        $this->assertEqualsWithDelta(120.00, (float) $invoice['total'], 0.001);

        $item = $this->items->find($itemId);

        $this->assertSame('invoiced', $item['status']);
        $this->assertSame((int) $invoice['id'], (int) $item['invoice_id'], 'the item must name the invoice that billed it');

        $this->assertSame(1, $this->invoiceCount());
    }

    public function test_a_second_sweep_does_not_bill_the_same_item_again(): void
    {
        $this->items->create($this->clientId, 'One-off charge', 120.00);

        $this->job->handle();
        $this->job->handle();

        $this->assertSame(1, $this->invoiceCount(), 'a daily job runs daily; it must not bill the same charge twice');
    }

    public function test_a_failure_while_marking_the_item_leaves_no_invoice_behind(): void
    {
        // The atomicity property. Without the transaction the invoice is committed and
        // the item stays pending, so the NEXT sweep raises a second invoice for the
        // same charge — and nothing in the data says the client was billed twice.
        //
        // Injected with a trigger that refuses the UPDATE, because the real failure mode
        // is a crash between two statements and cannot be provoked through the job's
        // API. The fault is a genuine SQL error raised at exactly the right moment.
        $this->createBlockingTrigger();

        try {
            $this->items->create($this->clientId, 'Charge that cannot be linked', 120.00);

            try {
                $this->job->handle();
                $this->fail('the injected failure should have propagated out of the job');
            } catch (\Throwable) {
                // Expected. What matters is the state left behind, asserted below.
            }

            $this->assertSame(
                0,
                $this->invoiceCount(),
                'an invoice that could not be linked to the item it bills must not survive'
            );
        } finally {
            $this->db->statement('DROP TRIGGER IF EXISTS trg_billable_items_block_mark');
        }
    }

    /**
     * Refuses the transition that marks an item billed, and only that transition.
     *
     * Narrow on purpose: a trigger that refused every UPDATE would also break the
     * cancellation and edit paths, so a test using it would prove nothing about this
     * one write.
     */
    private function createBlockingTrigger(): void
    {
        $this->db->statement(
            "CREATE TRIGGER trg_billable_items_block_mark BEFORE UPDATE ON billable_items
             FOR EACH ROW
             BEGIN
                 IF NEW.invoice_id IS NOT NULL AND OLD.invoice_id IS NULL THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure while marking billed';
                 END IF;
             END"
        );
    }

    private function invoiceCount(): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM invoices')['c'];
    }
}
