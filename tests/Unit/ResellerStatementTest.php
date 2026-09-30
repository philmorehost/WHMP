<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerLedgerRepository;
use CodeVault\Reseller\ResellerLedgerService;
use CodeVault\Reseller\ResellerStatementRepository;
use CodeVault\Reseller\ResellerStatementService;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The numbered statement: what "issued" buys you that "viewed" does not.
 *
 * The view's own tests prove the arithmetic. These exist to prove the ONE thing a
 * document has that a page cannot have — that it cannot change afterwards.
 *
 * THE ASSERTION THAT MATTERS: issue a statement, then add an entry dated INSIDE
 * the period it covers, and read it again. The document must be unmoved while the
 * live view of the same period HAS moved. Asserting only the first half would pass
 * if entries were being ignored altogether, so both halves are asserted together
 * and neither can be satisfied by the other.
 *
 * Nothing here is a test of money rules — those belong to ResellerLedgerTest.
 */
final class ResellerStatementTest extends DatabaseTestCase
{
    private ResellerStatementService $statements;
    private ResellerStatementRepository $statementRepo;
    private ResellerLedgerService $ledger;
    private SettingsRepository $settings;
    private ClientRepository $clients;
    private ResellerStoreRepository $storeRepo;
    private int $resellerClientId;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->settings = new SettingsRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->storeRepo = new ResellerStoreRepository($this->db);
        $this->statementRepo = new ResellerStatementRepository($this->db);

        $currency = new CurrencyService(new CurrencyRepository($this->db));
        $stores = new ResellerStoreService(
            $this->storeRepo,
            new ResellerStoreLocator($this->storeRepo, new \CodeVault\Config(sys_get_temp_dir())),
            new DomainVerifier()
        );

        $this->resellerClientId = $this->clients->create([
            'email' => 'statement-owner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Store',
            'last_name' => 'Owner',
        ]);

        $this->storeId = (int) $stores->openForClient($this->resellerClientId, 'Statements')['store']['id'];

        $this->ledger = new ResellerLedgerService(
            new ResellerLedgerRepository($this->db),
            $this->storeRepo,
            $this->clients,
            $currency,
            $this->settings
        );

        $this->statements = new ResellerStatementService(
            $this->statementRepo,
            $this->ledger,
            $this->settings,
            $currency
        );
    }

    // ------------------------------------------------------- what issued means

    public function test_an_issued_statement_cannot_be_changed_by_a_later_entry_in_the_same_period(): void
    {
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(100.0, '2026-08-10 09:00:00'), '2026-08-10 09:00:00');

        $issued = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->assertNotNull($issued);
        $this->assertEqualsWithDelta(100.0, (float) $issued['closing_base'], 0.001);
        $this->assertEqualsWithDelta(1.0, (float) $issued['entry_count'], 0.001);

        // A second receipt DATED INSIDE the same period — the shape a late
        // back-dated entry arrives in, and the whole reason the document is frozen.
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(50.0, '2026-08-20 09:00:00'), '2026-08-20 09:00:00');

        $reread = $this->statements->find((int) $issued['id']);

        // THE DOCUMENT IS UNMOVED...
        $this->assertEqualsWithDelta(100.0, (float) $reread['closing_base'], 0.001, 'the issued document must not change');
        $this->assertEqualsWithDelta(1.0, (float) $reread['entry_count'], 0.001);
        $this->assertCount(1, $reread['lines'], 'the frozen lines must not gain a row');

        // ...AND THE VIEW HAS MOVED. Without this half the test would also pass if
        // entries were simply not being read at all, which would prove nothing.
        $live = $this->ledger->statementFor($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');
        $this->assertEqualsWithDelta(
            150.0,
            (float) $live['closing_base'],
            0.001,
            'the live view must still reflect the new entry, or the freeze proves nothing'
        );
    }

    public function test_an_issued_statement_survives_its_own_ledger_row_being_deleted(): void
    {
        $this->ledger->accrueStoreReceipt($this->paidStoreOrder(75.0, '2026-08-10 09:00:00'), '2026-08-10 09:00:00');

        $issued = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        // The document is self-contained on purpose: it must survive a correction
        // or deletion in the ledger, because the reseller has already filed it.
        $this->db->update('DELETE FROM reseller_ledger WHERE reseller_id = ?', [$this->storeId]);

        $reread = $this->statements->find((int) $issued['id']);

        $this->assertEqualsWithDelta(75.0, (float) $reread['closing_base'], 0.001);
        $this->assertCount(1, $reread['lines'], 'the frozen lines must outlive the ledger row');
        $this->assertEqualsWithDelta(75.0, (float) $reread['lines'][0]['amount'], 0.001);
    }

    public function test_the_identity_is_frozen_at_issue_and_a_later_change_does_not_reach_back(): void
    {
        $this->settings->set('company.name', 'Original Hosting Ltd');
        $this->settings->set('company.tax_number', 'VAT-1111');

        $issued = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->assertSame('VAT-1111', $issued['identity']['tax_number']);
        $this->assertSame('Original Hosting Ltd', $issued['identity']['legal_name']);

        // The company re-registers. Documents already issued must still say who
        // issued them at the time — otherwise "issued by" describes whoever we are
        // today, which is not a statement about the past.
        $this->settings->set('company.name', 'Renamed Hosting Ltd');
        $this->settings->set('company.tax_number', 'VAT-9999');

        $reread = $this->statements->find((int) $issued['id']);

        $this->assertSame('VAT-1111', $reread['identity']['tax_number'], 'a new VAT number must not rewrite an old document');
        $this->assertSame('Original Hosting Ltd', $reread['identity']['legal_name']);
    }

    // ------------------------------------------------------------- numbering

    public function test_issuing_the_same_period_twice_returns_the_same_number_and_writes_no_second_row(): void
    {
        $first = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');
        $second = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->assertSame($first['number'], $second['number'], 'a repeated issue must not mint a new number');
        $this->assertFalse($first['already_issued']);
        $this->assertTrue($second['already_issued']);

        // One row, not two. A burned sequence value looks exactly like a lost
        // document, so this is the difference between a sequence you can audit and
        // one you cannot.
        $this->assertCount(1, $this->statements->listing($this->storeId));
    }

    public function test_numbers_are_sequential_within_a_store_and_year(): void
    {
        $august = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');
        $september = $this->statements->issue($this->storeId, '2026-09-01 00:00:00', '2026-09-30 23:59:59');

        $this->assertSame('STMT-2026-0001', $august['number']);
        $this->assertSame('STMT-2026-0002', $september['number']);
    }

    public function test_the_sequence_restarts_for_a_new_year(): void
    {
        $december = $this->statements->issue($this->storeId, '2026-12-01 00:00:00', '2026-12-31 23:59:59');
        $january = $this->statements->issue($this->storeId, '2027-01-01 00:00:00', '2027-01-31 23:59:59');

        $this->assertSame('STMT-2026-0001', $december['number']);
        $this->assertSame('STMT-2027-0001', $january['number'], 'the year is part of the number');
    }

    public function test_a_second_store_numbers_from_one_again(): void
    {
        $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        // The sequence is per store. A shared one would make every reseller able to
        // read the platform's volume off their own statement number.
        $otherClient = $this->clients->create([
            'email' => 'statement-owner-2@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Second',
            'last_name' => 'Store',
        ]);

        $stores = new ResellerStoreService(
            $this->storeRepo,
            new ResellerStoreLocator($this->storeRepo, new \CodeVault\Config(sys_get_temp_dir())),
            new DomainVerifier()
        );
        $otherStoreId = (int) $stores->openForClient($otherClient, 'Second')['store']['id'];

        $other = $this->statements->issue($otherStoreId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->assertSame('STMT-2026-0001', $other['number'], 'each store has its own sequence');
    }

    public function test_the_stored_number_matches_the_sequence_it_was_allocated(): void
    {
        $issued = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        // The (reseller_id, number) key only helps if the stored string really is
        // derived from the stored seq. Asserting the pair together is the only way
        // to catch a formatter that pads differently from how the allocator counts.
        $this->assertSame(
            ResellerStatementRepository::formatNumber((int) $issued['period_year'], (int) $issued['seq']),
            $issued['number']
        );
    }

    // ------------------------------------------------------------- identity

    public function test_missing_identity_reports_exactly_the_blank_fields(): void
    {
        $this->settings->set('company.name', 'Set Hosting Ltd');
        $this->settings->set('company.tax_number', 'VAT-123');
        $this->settings->set('company.registration_number', '');
        $this->settings->set('company.address', '1 Set Street');
        $this->settings->set('company.email', '');
        $this->settings->set('company.phone', '');

        $missing = $this->statements->missingIdentity();

        // Named as LABELS, because this list is shown to an admin next to the
        // settings they have to go and fill in.
        $this->assertSame(['Company registration number', 'Contact email', 'Contact phone'], $missing);
    }

    public function test_an_issued_statement_carries_the_period_it_covers(): void
    {
        $issued = $this->statements->issue($this->storeId, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->assertSame('2026-08-01 00:00:00', $issued['period_from']);
        $this->assertSame('2026-08-31 23:59:59', $issued['period_to']);
        $this->assertSame(2026, (int) $issued['period_year']);
        $this->assertNotNull($issued['issued_at']);
    }

    public function test_the_settings_form_the_controller_and_the_document_all_name_the_same_keys(): void
    {
        // A seam no compiler checks, and the failure is completely silent: the form
        // writes name="company_x", the controller reads a different string, and the
        // field simply never saves. Each half looks right on its own, so this reads
        // both files and the identity() keys together.
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/configuration/general.php');
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Configuration/GeneralSettingsController.php');
        $identity = $this->statements->identity();

        $seams = [
            ['field' => 'company_tax_number', 'setting' => 'company.tax_number', 'identity' => 'tax_number'],
            ['field' => 'company_registration_number', 'setting' => 'company.registration_number', 'identity' => 'registration_number'],
            ['field' => 'company_address', 'setting' => 'company.address', 'identity' => 'address'],
            ['field' => 'company_phone', 'setting' => 'company.phone', 'identity' => 'phone'],
        ];

        foreach ($seams as $seam) {
            $this->assertStringContainsString(
                'name="' . $seam['field'] . '"',
                $view,
                'no form field is named ' . $seam['field']
            );
            $this->assertStringContainsString(
                "input('" . $seam['field'] . "'",
                $controller,
                'the controller never reads ' . $seam['field']
            );
            $this->assertStringContainsString(
                "set('" . $seam['setting'] . "'",
                $controller,
                'the controller never saves ' . $seam['setting']
            );
            $this->assertArrayHasKey(
                $seam['identity'],
                $identity,
                'the document never reads ' . $seam['setting']
            );
        }
    }

    /**
     * A PAID store order and its invoice, so the accrual has something real to read.
     * Returns the invoice id, which is what the ledger is keyed on.
     */
    private function paidStoreOrder(float $retail, string $at): int
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

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$customerId, $orderId, 'paid', $retail, 0.0, $retail, substr($at, 0, 10), $at, $at]
        );
    }
}
