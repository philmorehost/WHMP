<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencySelection;
use CodeVault\Billing\CurrencySwitchController;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * Switching currency while ordering (status doc §3.3).
 *
 * Two different operations live behind one-looking UI, and the whole point of
 * this test is that they stay different:
 *
 *  - the storefront/header picker (`select`) changes only how prices are
 *    PREVIEWED — it must never re-denominate a client's account (that was the
 *    `0ffc402` fix: a header click re-priced the whole account);
 *  - the deliberate `applyToAccount` changes the ACCOUNT currency and moves
 *    every live amount with it, so it requires the confirmation the warning
 *    asks for.
 *
 * The refusal test is paired with a success test on purpose: a refusal that is
 * never checked against a working success path can pass for the wrong reason
 * (the affiliate-payout bug this codebase already paid for once).
 */
final class CheckoutCurrencySwitchTest extends DatabaseTestCase
{
    private CurrencySwitchController $controller;
    private CurrencyRepository $currencies;
    private ClientRepository $clients;
    private SessionManager $session;
    private string $configDir;
    private int $clientId;
    private int $ngnId;
    private int $usdId;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $_SESSION = [];
        $this->configDir = sys_get_temp_dir() . '/codevault-curswitch-' . uniqid();
        mkdir($this->configDir);
        $this->session = new SessionManager(new Config($this->configDir));

        $this->currencies = new CurrencyRepository($this->db);
        $this->clients = new ClientRepository($this->db);

        $this->ngnId = $this->currencies->create('NGN', '₦', 1490.0000);
        $this->usdId = (int) $this->currencies->default()['id'];

        // The client starts on the base currency (currency_id NULL). An unpaid
        // invoice is the "live amount" that must move with them.
        $this->clientId = $this->clients->create([
            'email' => 'switch-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'first_name' => 'Cur',
            'last_name' => 'Rency',
        ]);

        $this->invoiceId = $this->insertUnpaidInvoice($this->clientId, 100.00);

        $this->controller = new CurrencySwitchController(
            $this->currencies,
            new CurrencySelection($this->session),
            new ClientAuthGuard($this->session, $this->clients),
            $this->clients,
            $this->session
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->configDir);
        parent::tearDown();
    }

    public function test_confirmed_switch_moves_the_account_and_its_live_amounts(): void
    {
        $this->signIn();

        $response = $this->controller->applyToAccount($this->post([
            'currency_id' => (string) $this->ngnId,
            'confirm' => '1',
            'redirect' => '/cart',
        ]));

        $this->assertSame(302, $response->status());
        $this->assertSame('/cart', $response->headers()['Location'] ?? null);

        // The account now reads naira.
        $client = $this->clients->find($this->clientId);
        $this->assertSame($this->ngnId, (int) $client['currency_id']);

        // The live (unpaid) invoice was re-denominated: 100 base x 1490, stored
        // in the new currency with rate 1.0 (denominated shape), not as a base
        // figure with a display multiplier.
        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$this->invoiceId]);
        $this->assertSame($this->ngnId, (int) $invoice['currency_id']);
        $this->assertEqualsWithDelta(149000.00, (float) $invoice['total'], 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $invoice['currency_rate'], 0.0001);

        // And the client is warned in plain words, not left to discover it.
        $notice = $this->session->get('_flash')['currency_notice'] ?? '';
        $this->assertStringContainsString('NGN', (string) $notice);
        $this->assertStringContainsString('recalculated', (string) $notice);
    }

    public function test_switch_without_confirmation_changes_nothing(): void
    {
        $this->signIn();

        $this->controller->applyToAccount($this->post([
            'currency_id' => (string) $this->ngnId,
            'redirect' => '/cart',
            // no 'confirm'
        ]));

        $client = $this->clients->find($this->clientId);
        $this->assertSame($this->usdId, (int) $client['currency_id'], 'An unconfirmed switch must not re-denominate the account.');

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$this->invoiceId]);
        $this->assertEqualsWithDelta(100.00, (float) $invoice['total'], 0.01);

        $error = $this->session->get('_flash')['currency_error'] ?? '';
        $this->assertStringContainsString('confirmation', (string) $error);
    }

    public function test_a_guest_gets_a_display_choice_and_no_account_change(): void
    {
        // Not signed in.
        $this->controller->applyToAccount($this->post([
            'currency_id' => (string) $this->ngnId,
            'confirm' => '1',
        ]));

        $client = $this->clients->find($this->clientId);
        $this->assertSame($this->usdId, (int) $client['currency_id'], 'A guest has no account to re-denominate.');

        // The browsing choice IS recorded, so the storefront prices in it.
        $this->assertSame($this->ngnId, $this->session->get('currency_id'));
    }

    /**
     * The regression guard for `0ffc402`: the header/browse picker must never
     * re-denominate the account. If someone "improves" select() to call
     * updateCurrency(), this fails.
     */
    public function test_the_browse_picker_still_does_not_touch_the_account(): void
    {
        $this->signIn();

        $this->controller->select($this->post([
            'currency_id' => (string) $this->ngnId,
            'redirect' => '/store',
        ]));

        $client = $this->clients->find($this->clientId);
        $this->assertSame($this->usdId, (int) $client['currency_id'], 'Browsing in another currency must not move the account.');

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$this->invoiceId]);
        $this->assertEqualsWithDelta(100.00, (float) $invoice['total'], 0.01);

        // The session choice is still recorded.
        $this->assertSame($this->ngnId, $this->session->get('currency_id'));
    }

    public function test_switching_to_the_currency_the_account_already_uses_is_a_no_op(): void
    {
        $this->signIn();
        // Move the account to naira first.
        $this->controller->applyToAccount($this->post(['currency_id' => (string) $this->ngnId, 'confirm' => '1']));
        $this->session->set('_flash', []);

        $this->controller->applyToAccount($this->post(['currency_id' => (string) $this->ngnId, 'confirm' => '1']));

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$this->invoiceId]);
        // Converting twice would have scaled it to 1490 x 1490; it must stay put.
        $this->assertEqualsWithDelta(149000.00, (float) $invoice['total'], 0.01);

        $notice = $this->session->get('_flash')['currency_notice'] ?? '';
        $this->assertStringContainsString('already in NGN', (string) $notice);
    }

    private function signIn(): void
    {
        $this->session->set('client_id', $this->clientId);
    }

    /** @param array<string, string> $body */
    private function post(array $body): Request
    {
        return new Request([], $body, ['REQUEST_METHOD' => 'POST'], []);
    }

    private function insertUnpaidInvoice(int $clientId, float $total): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, discount_amount, total, currency_id, currency_rate, due_date, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL, 1.0000, ?, ?, ?)',
            [$clientId, 'unpaid', $total, 0.0, 0.0, $total, $now, $now, $now]
        );
    }
}
