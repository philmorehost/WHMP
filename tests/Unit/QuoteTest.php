<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\QuoteExpiryJob;
use CodeVault\Billing\QuoteRepository;
use CodeVault\Billing\QuoteService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Tests\Support\DatabaseTestCase;

final class QuoteTest extends DatabaseTestCase
{
    private QuoteRepository $quotes;
    private QuoteService $service;
    private InvoiceRepository $invoices;
    private ClientRepository $clients;
    private HookDispatcher $hooks;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->quotes = new QuoteRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->hooks = new HookDispatcher();

        $this->service = new QuoteService(
            $this->db,
            $this->quotes,
            $this->invoices,
            $this->clients,
            new CurrencyService(new CurrencyRepository($this->db)),
            $this->hooks
        );

        $this->clientId = $this->clients->create([
            'email' => 'quote-subject@example.test',
            'password' => 'whatever123',
            'first_name' => 'Nora',
            'last_name' => 'Client',
        ]);
    }

    // --- create() --------------------------------------------------------

    public function test_create_computes_total_from_items_and_starts_as_draft(): void
    {
        $result = $this->service->create($this->clientId, 'Website bundle', '2027-01-01', [
            ['description' => 'Hosting (annual)', 'amount' => 120.00],
            ['description' => 'SSL certificate', 'amount' => 15.00],
        ], 1);

        $this->assertTrue($result['success']);
        $quote = $this->quotes->find($result['id']);

        $this->assertSame('draft', $quote['status']);
        $this->assertEqualsWithDelta(135.00, (float) $quote['total'], 0.001);
        $this->assertSame('2027-01-01', $quote['valid_until']);
        $this->assertCount(2, $this->quotes->items($result['id']));
    }

    public function test_create_ignores_blank_or_zero_rows(): void
    {
        $result = $this->service->create($this->clientId, 'Subject', null, [
            ['description' => 'Real item', 'amount' => 10.00],
            ['description' => '', 'amount' => 5.00],
            ['description' => 'Zero amount', 'amount' => 0],
        ], null);

        $quote = $this->quotes->find($result['id']);
        $this->assertEqualsWithDelta(10.00, (float) $quote['total'], 0.001);
        $this->assertCount(1, $this->quotes->items($result['id']));
    }

    public function test_create_rejects_nonexistent_client(): void
    {
        $result = $this->service->create(999999, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);

        $this->assertFalse($result['success']);
    }

    public function test_create_rejects_empty_subject(): void
    {
        $result = $this->service->create($this->clientId, '', null, [['description' => 'X', 'amount' => 10]], null);

        $this->assertFalse($result['success']);
    }

    public function test_create_rejects_no_valid_items(): void
    {
        $result = $this->service->create($this->clientId, 'Subject', null, [['description' => '', 'amount' => 0]], null);

        $this->assertFalse($result['success']);
    }

    // --- send() ------------------------------------------------------------

    public function test_send_moves_draft_to_sent(): void
    {
        $created = $this->service->create($this->clientId, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);

        $result = $this->service->send($created['id']);

        $this->assertTrue($result['success']);
        $this->assertSame('sent', $this->quotes->find($created['id'])['status']);
    }

    public function test_send_rejects_a_non_draft_quote(): void
    {
        $created = $this->service->create($this->clientId, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);
        $this->service->send($created['id']);

        $result = $this->service->send($created['id']);

        $this->assertFalse($result['success']);
    }

    // --- accept() ------------------------------------------------------------

    public function test_accept_converts_a_sent_quote_into_a_real_invoice_with_matching_total(): void
    {
        $created = $this->service->create($this->clientId, 'Website bundle', null, [
            ['description' => 'Hosting (annual)', 'amount' => 120.00],
            ['description' => 'SSL certificate', 'amount' => 15.00],
        ], null);
        $this->service->send($created['id']);

        $result = $this->service->accept($created['id'], $this->clientId);

        $this->assertTrue($result['success']);
        $invoice = $this->invoices->find($result['invoiceId']);
        $this->assertNotNull($invoice);
        $this->assertEqualsWithDelta(135.00, (float) $invoice['total'], 0.001);
        $this->assertSame($this->clientId, (int) $invoice['client_id']);

        $invoiceItems = $this->invoices->items($result['invoiceId']);
        $this->assertCount(2, $invoiceItems);

        $quote = $this->quotes->find($created['id']);
        $this->assertSame('accepted', $quote['status']);
        $this->assertSame($result['invoiceId'], (int) $quote['invoice_id']);
    }

    public function test_accept_fires_the_quote_accepted_hook(): void
    {
        $fired = [];
        $this->hooks->register(HookPoints::QUOTE_ACCEPTED, function (array $payload) use (&$fired) {
            $fired[] = $payload;
        });

        $created = $this->service->create($this->clientId, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);
        $this->service->send($created['id']);
        $this->service->accept($created['id'], $this->clientId);

        $this->assertCount(1, $fired);
        $this->assertSame($created['id'], $fired[0]['quoteId']);
    }

    public function test_accept_rejects_a_draft_quote(): void
    {
        $created = $this->service->create($this->clientId, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);

        $result = $this->service->accept($created['id'], $this->clientId);

        $this->assertFalse($result['success']);
        $this->assertSame('draft', $this->quotes->find($created['id'])['status']);
    }

    public function test_accept_rejects_a_different_clients_quote(): void
    {
        $otherClientId = $this->clients->create([
            'email' => 'other@example.test',
            'password' => 'whatever123',
            'first_name' => 'Other',
            'last_name' => 'Client',
        ]);

        $created = $this->service->create($this->clientId, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);
        $this->service->send($created['id']);

        $result = $this->service->accept($created['id'], $otherClientId);

        $this->assertFalse($result['success']);
    }

    public function test_accept_is_not_repeatable(): void
    {
        $created = $this->service->create($this->clientId, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);
        $this->service->send($created['id']);
        $first = $this->service->accept($created['id'], $this->clientId);
        $this->assertTrue($first['success']);

        $second = $this->service->accept($created['id'], $this->clientId);

        $this->assertFalse($second['success']);
    }

    // --- decline() -----------------------------------------------------------

    public function test_decline_moves_sent_to_declined(): void
    {
        $created = $this->service->create($this->clientId, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);
        $this->service->send($created['id']);

        $result = $this->service->decline($created['id'], $this->clientId);

        $this->assertTrue($result['success']);
        $this->assertSame('declined', $this->quotes->find($created['id'])['status']);
    }

    public function test_decline_fires_the_quote_declined_hook(): void
    {
        $fired = [];
        $this->hooks->register(HookPoints::QUOTE_DECLINED, function (array $payload) use (&$fired) {
            $fired[] = $payload;
        });

        $created = $this->service->create($this->clientId, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);
        $this->service->send($created['id']);
        $this->service->decline($created['id'], $this->clientId);

        $this->assertCount(1, $fired);
    }

    // --- QuoteRepository::overdue() (backs QuoteExpiryJob) -------------------

    public function test_overdue_returns_only_draft_and_sent_quotes_past_valid_until(): void
    {
        $expiredDraft = $this->service->create($this->clientId, 'Expired draft', '2000-01-01', [['description' => 'X', 'amount' => 10]], null);
        $expiredSent = $this->service->create($this->clientId, 'Expired sent', '2000-01-01', [['description' => 'X', 'amount' => 10]], null);
        $this->service->send($expiredSent['id']);
        $notYetExpired = $this->service->create($this->clientId, 'Future', '2999-01-01', [['description' => 'X', 'amount' => 10]], null);
        $noExpiry = $this->service->create($this->clientId, 'No expiry', null, [['description' => 'X', 'amount' => 10]], null);

        $overdue = $this->quotes->overdue();
        $overdueIds = array_map(static fn (array $q) => (int) $q['id'], $overdue);

        $this->assertContains($expiredDraft['id'], $overdueIds);
        $this->assertContains($expiredSent['id'], $overdueIds);
        $this->assertNotContains($notYetExpired['id'], $overdueIds);
        $this->assertNotContains($noExpiry['id'], $overdueIds);
    }

    public function test_overdue_excludes_already_accepted_or_declined_quotes(): void
    {
        $accepted = $this->service->create($this->clientId, 'Accepted', '2000-01-01', [['description' => 'X', 'amount' => 10]], null);
        $this->service->send($accepted['id']);
        $this->service->accept($accepted['id'], $this->clientId);

        $overdueIds = array_map(static fn (array $q) => (int) $q['id'], $this->quotes->overdue());

        $this->assertNotContains($accepted['id'], $overdueIds);
    }

    // --- QuoteExpiryJob --------------------------------------------------------

    public function test_quote_expiry_job_marks_overdue_quotes_as_expired(): void
    {
        $expired = $this->service->create($this->clientId, 'Expired', '2000-01-01', [['description' => 'X', 'amount' => 10]], null);
        $this->service->send($expired['id']);
        $fresh = $this->service->create($this->clientId, 'Fresh', '2999-01-01', [['description' => 'X', 'amount' => 10]], null);

        (new QuoteExpiryJob($this->quotes))->handle();

        $this->assertSame('expired', $this->quotes->find($expired['id'])['status']);
        $this->assertSame('draft', $this->quotes->find($fresh['id'])['status']);
    }

    // --- delete() (admin draft-only removal) ----------------------------------

    public function test_delete_removes_a_draft_quote(): void
    {
        $created = $this->service->create($this->clientId, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);

        $this->quotes->delete($created['id']);

        $this->assertNull($this->quotes->find($created['id']));
    }

    public function test_delete_does_not_remove_a_sent_quote(): void
    {
        $created = $this->service->create($this->clientId, 'Subject', null, [['description' => 'X', 'amount' => 10]], null);
        $this->service->send($created['id']);

        $this->quotes->delete($created['id']);

        $this->assertNotNull($this->quotes->find($created['id']));
    }

    // --- repository listing --------------------------------------------------

    public function test_repository_all_joins_client_email_newest_first(): void
    {
        $this->service->create($this->clientId, 'First', null, [['description' => 'A', 'amount' => 1]], null);
        $second = $this->service->create($this->clientId, 'Second', null, [['description' => 'B', 'amount' => 2]], null);

        $all = $this->quotes->all();
        $this->assertCount(2, $all);
        $this->assertSame($second['id'], (int) $all[0]['id'], 'newest first');
        $this->assertSame('quote-subject@example.test', $all[0]['client_email']);
    }
}
