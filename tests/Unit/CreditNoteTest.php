<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ClientCreditRepository;
use CodeVault\Billing\CreditNoteRepository;
use CodeVault\Billing\CreditNoteService;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

final class CreditNoteTest extends DatabaseTestCase
{
    private CreditNoteRepository $creditNotes;
    private CreditNoteService $service;
    private ClientCreditRepository $ledger;
    private ClientRepository $clients;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->creditNotes = new CreditNoteRepository($this->db);
        $this->ledger = new ClientCreditRepository($this->db);

        $this->service = new CreditNoteService(
            $this->db,
            $this->creditNotes,
            $this->ledger,
            $this->clients,
            new CurrencyService(new CurrencyRepository($this->db))
        );

        $this->clientId = $this->clients->create([
            'email' => 'credit-note-subject@example.test',
            'password' => 'whatever123',
            'first_name' => 'Nora',
            'last_name' => 'Client',
        ]);
    }

    public function test_issue_creates_note_items_and_grants_linked_ledger_credit(): void
    {
        $result = $this->service->issue($this->clientId, null, 'Service cancellation refund', [
            ['description' => 'Unused hosting time', 'amount' => 15.50],
            ['description' => 'Setup fee refund', 'amount' => 9.99],
        ], 1);

        $this->assertTrue($result['success']);
        $noteId = $result['id'];

        $note = $this->creditNotes->find($noteId);
        $this->assertNotNull($note);
        $this->assertEqualsWithDelta(25.49, (float) $note['total'], 0.001);
        $this->assertSame('Service cancellation refund', $note['reason']);

        $items = $this->creditNotes->items($noteId);
        $this->assertCount(2, $items);

        $this->assertEqualsWithDelta(25.49, $this->ledger->balance($this->clientId), 0.001);

        $ledgerRows = $this->ledger->forClient($this->clientId);
        $this->assertCount(1, $ledgerRows);
        $this->assertSame($noteId, (int) $ledgerRows[0]['credit_note_id'], 'the ledger entry must trace back to the credit note that produced it');
    }

    public function test_issue_computes_total_from_items_ignoring_blank_or_zero_rows(): void
    {
        $result = $this->service->issue($this->clientId, null, 'Adjustment', [
            ['description' => 'Real item', 'amount' => 10.00],
            ['description' => '', 'amount' => 5.00],
            ['description' => 'Zero amount', 'amount' => 0],
        ], null);

        $this->assertTrue($result['success']);
        $note = $this->creditNotes->find($result['id']);
        $this->assertEqualsWithDelta(10.00, (float) $note['total'], 0.001);
        $this->assertCount(1, $this->creditNotes->items($result['id']));
    }

    public function test_issue_rejects_a_nonexistent_client(): void
    {
        $result = $this->service->issue(999999, null, 'Refund', [['description' => 'X', 'amount' => 10]], null);

        $this->assertFalse($result['success']);
        $this->assertSame(0, count($this->creditNotes->all()));
    }

    public function test_issue_rejects_an_empty_reason(): void
    {
        $result = $this->service->issue($this->clientId, null, '', [['description' => 'X', 'amount' => 10]], null);

        $this->assertFalse($result['success']);
    }

    public function test_issue_rejects_when_no_valid_items_remain(): void
    {
        $result = $this->service->issue($this->clientId, null, 'Refund', [
            ['description' => '', 'amount' => 0],
        ], null);

        $this->assertFalse($result['success']);
        $this->assertSame(0.0, $this->ledger->balance($this->clientId));
    }

    public function test_issue_links_the_related_invoice_when_given(): void
    {
        $now = date('Y-m-d H:i:s');
        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'paid', 20.00, 20.00, substr($now, 0, 10), $now, $now]
        );

        $result = $this->service->issue($this->clientId, $invoiceId, 'Partial refund', [['description' => 'Refund', 'amount' => 5]], null);

        $note = $this->creditNotes->find($result['id']);
        $this->assertSame($invoiceId, (int) $note['invoice_id']);
    }

    public function test_repository_all_joins_client_email_newest_first(): void
    {
        $this->service->issue($this->clientId, null, 'First', [['description' => 'A', 'amount' => 1]], null);
        $second = $this->service->issue($this->clientId, null, 'Second', [['description' => 'B', 'amount' => 2]], null);

        $all = $this->creditNotes->all();
        $this->assertCount(2, $all);
        $this->assertSame($second['id'], (int) $all[0]['id'], 'newest first');
        $this->assertSame('credit-note-subject@example.test', $all[0]['client_email']);
    }
}
