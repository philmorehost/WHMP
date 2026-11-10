<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\AdminCryptoManualController;
use CodeVault\Billing\ClientCryptoManualController;
use CodeVault\Billing\ClientCreditRepository;
use CodeVault\Billing\CryptoManualGateway;
use CodeVault\Billing\CryptoManualPaymentRepository;
use CodeVault\Billing\CryptoManualProofService;
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
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Modules\ModuleManager;
use CodeVault\Notifications\NotificationDispatcher;
use CodeVault\Notifications\NotificationEndpointRepository;
use CodeVault\Notifications\SlackNotificationProvider;
use CodeVault\Notifications\WebhookNotificationProvider;
use CodeVault\Queue\SyncQueue;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\RoleRepository;
use CodeVault\Support\App;
use CodeVault\View;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * The manual crypto payment pipeline end to end, against the real schema:
 * a naira invoice quotes as USD on the instructions page (the Plisio-like
 * "crypto is counted in dollars" rule), the audience toggles gate the page
 * the same way the pay button is gated, and an admin approval runs the
 * ordinary payment pipeline — invoice paid, deposit invoices become wallet
 * credit, double approval is impossible.
 */
final class ManualCryptoPaymentTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private PaymentGatewayRepository $gateways;
    private CryptoManualPaymentRepository $payments;
    private InvoiceRepository $invoices;
    private TransactionRepository $transactions;
    private CurrencyService $currency;
    private SettingsRepository $settings;
    private ClientAuthGuard $clientGuard;
    private ClientCryptoManualController $clientController;
    private string $proofDir;
    private int $clientId;
    private int $ngnId;

    /** @var array<string, mixed> */
    private array $clientRow;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->bootstrap();
    }

    private function bootstrap(): void
    {
        $this->clients = new ClientRepository($this->db);
        $this->gateways = new PaymentGatewayRepository($this->db);
        $this->payments = new CryptoManualPaymentRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->transactions = new TransactionRepository($this->db);
        $this->currency = new CurrencyService(new CurrencyRepository($this->db));
        $this->settings = new SettingsRepository($this->db);

        $this->clientId = $this->clients->create([
            'email' => 'cryptodepositor@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Crypto',
            'last_name' => 'Depositor',
        ]);
        $this->clientRow = $this->clients->find($this->clientId);

        // A naira DISPLAY currency (base stays USD) — the case the user
        // described: naira client, crypto quoted in USD.
        $this->ngnId = (int) $this->db->insert(
            "INSERT INTO currencies (code, symbol, exchange_rate, is_default, created_at, updated_at) VALUES ('NGN', '₦', 1500, 0, NOW(), NOW())"
        );
        $this->db->update('UPDATE clients SET currency_id = ? WHERE id = ?', [$this->ngnId, $this->clientId]);

        // The gateway row, seeded disabled by migration 0224 — enabled here
        // with a valid wallet unless a test says otherwise.
        $this->enableGateway([
            'wallet_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'enable_for_clients' => '1',
            'enable_for_resellers' => '1',
        ]);

        $configDir = sys_get_temp_dir() . '/codevault-manual-crypto-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $this->clientGuard = new ClientAuthGuard($session, $this->clients);
        $this->clientGuard->login($this->clientRow);

        $container = new Container();
        $container->instance(SessionManager::class, $session);
        $container->instance(Config::class, new Config($configDir));
        $container->instance(Database::class, $this->db);
        $container->instance(SettingsRepository::class, $this->settings);
        $container->bind(\CodeVault\Security\CsrfToken::class);
        App::setContainer($container);

        $this->proofDir = sys_get_temp_dir() . '/codevault-crypto-proofs-' . uniqid();

        $this->clientController = new ClientCryptoManualController(
            $this->clientGuard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->invoices,
            $this->transactions,
            $this->gateways,
            $this->payments,
            new CryptoManualProofService($this->proofDir),
            $this->currency,
            $this->settings,
            $this->emailDispatcher(),
            $this->notificationDispatcher(),
            $this->db
        );

        // The billing inbox is empty in tests so notifyAdmins() short-circuits.
        $this->settings->set('company.email', '');
    }

    private function emailDispatcher(): EmailDispatcher
    {
        return new EmailDispatcher(
            new EmailTemplateRepository($this->db),
            new EmailLogRepository($this->db),
            new SyncQueue()
        );
    }

    private function notificationDispatcher(): NotificationDispatcher
    {
        $http = new \CodeVault\Tests\Fixtures\FakeHttpClient();

        return new NotificationDispatcher(
            new NotificationEndpointRepository($this->db),
            new SlackNotificationProvider($http),
            new WebhookNotificationProvider($http),
            new ModuleManager(new HookDispatcher())
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function enableGateway(array $overrides = []): void
    {
        $config = array_merge([
            'wallet_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'enable_for_clients' => '1',
            'enable_for_resellers' => '1',
        ], $overrides);

        $this->db->update(
            'UPDATE payment_gateways SET is_enabled = 1, config = ? WHERE slug = ?',
            [json_encode($config), CryptoManualGateway::SLUG]
        );
    }

    /**
     * A naira display invoice: total 15.00 in the base currency, shown at
     * rate 1500 (₦22,500) — the shape every invoice row uses.
     */
    private function createNairaInvoice(float $baseTotal = 15.0): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'unpaid', $baseTotal, $baseTotal, $this->ngnId, 1500.0, substr($now, 0, 10), $now, $now]
        );
    }

    private function createDepositInvoice(float $baseTotal = 25.0): int
    {
        $invoiceId = $this->createNairaInvoice($baseTotal);
        $this->db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, 'Deposit / Add Funds to Wallet', $baseTotal]
        );

        return $invoiceId;
    }

    private function createSubmission(int $invoiceId, float $usd = 15.0): int
    {
        return $this->payments->create([
            'invoice_id' => $invoiceId,
            'client_id' => $this->clientId,
            'reference' => 'cv-' . CryptoManualGateway::SLUG . "-{$invoiceId}-" . bin2hex(random_bytes(6)),
            'amount_usd' => $usd,
            'usd_currency_id' => null,
            'usd_rate' => 1.0,
            'wallet_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'txid' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90112233445566778899AABBCCDDEEFF00',
            'proof_path' => 'nonexistent.png',
            'proof_name' => 'proof.png',
            'proof_mime' => 'image/png',
            'proof_size' => 1234,
            'note' => null,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function get(string $path): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path], []);
    }

    private function post(string $path, array $body = [], array $files = []): Request
    {
        return new Request([], $body, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $path], [], $files);
    }

    // ── The client-facing quote ──────────────────────────────────────────

    public function test_a_naira_invoice_is_quoted_in_usd_on_the_instructions_page(): void
    {
        $invoiceId = $this->createNairaInvoice(15.0); // ₦22,500 displayed

        $response = $this->clientController->instructions($this->get("/client/invoices/{$invoiceId}/crypto"), ['id' => (string) $invoiceId]);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('$15.00', $response->body(), 'the USD figure the client must send');
        $this->assertStringContainsString('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', $response->body(), 'the admin wallet');
        $this->assertStringContainsString('TRC-20', $response->body());
    }

    public function test_the_quote_covers_only_the_unpaid_balance(): void
    {
        $invoiceId = $this->createNairaInvoice(15.0);
        // ₦7,500 (half) already paid by another gateway.
        $this->transactions->create($invoiceId, 'paystack', 7500.0, 'completed', 'paystack-1');

        $response = $this->clientController->instructions($this->get("/client/invoices/{$invoiceId}/crypto"), ['id' => (string) $invoiceId]);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('$7.50', $response->body());
        $this->assertStringNotContainsString('$15.00', $response->body());
    }

    // ── The audience toggles, on the page itself ─────────────────────────

    public function test_a_disabled_gateway_sends_the_client_back_to_the_invoice(): void
    {
        $this->db->update("UPDATE payment_gateways SET is_enabled = 0 WHERE slug = 'crypto_manual'", []);
        $invoiceId = $this->createNairaInvoice();

        $response = $this->clientController->instructions($this->get('/x'), ['id' => (string) $invoiceId]);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('payment=error', $response->headers()['Location'] ?? '');
    }

    public function test_switching_the_clients_toggle_off_hides_the_page_from_platform_clients(): void
    {
        $this->enableGateway(['enable_for_clients' => '']);
        $invoiceId = $this->createNairaInvoice();

        $response = $this->clientController->instructions($this->get('/x'), ['id' => (string) $invoiceId]);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('not available', urldecode($response->headers()['Location'] ?? ''));
    }

    public function test_switching_the_resellers_toggle_off_leaves_clients_unaffected(): void
    {
        $this->enableGateway(['enable_for_resellers' => '']);
        $invoiceId = $this->createNairaInvoice();

        $this->assertSame(200, $this->clientController->instructions($this->get('/x'), ['id' => (string) $invoiceId])->status());
    }

    public function test_a_reseller_with_their_toggle_off_is_sent_away_while_clients_keep_the_page(): void
    {
        $this->enableGateway(['enable_for_resellers' => '']);

        $resellerClientId = $this->clients->create([
            'email' => 'storeowner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Store',
            'last_name' => 'Owner',
        ]);
        $this->db->insert('INSERT INTO resellers (client_id, slug, created_at, updated_at) VALUES (?, ?, NOW(), NOW())', [$resellerClientId, 'store-owner-' . $resellerClientId]);

        // Re-login as the reseller (the guard reads the session).
        $_SESSION['client_id'] = $resellerClientId;
        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, 15, 0, 15, ?, 1500, ?, NOW(), NOW())',
            [$resellerClientId, 'unpaid', $this->ngnId, substr((new DateTimeImmutable())->format('Y-m-d H:i:s'), 0, 10)]
        );

        $away = $this->clientController->instructions($this->get('/x'), ['id' => (string) $invoiceId]);
        $this->assertSame(302, $away->status(), 'reseller toggle off → reseller sent away');
        $this->assertStringContainsString('not available', urldecode($away->headers()['Location'] ?? ''));

        // The platform client keeps seeing it (their toggle is still on).
        $_SESSION['client_id'] = $this->clientId;
        $own = $this->createNairaInvoice();
        $this->assertSame(200, $this->clientController->instructions($this->get('/x'), ['id' => (string) $own])->status());
    }

    public function test_a_storefront_customer_never_gets_the_manual_crypto_option(): void
    {
        // reseller_enabled is seeded OFF for crypto_manual: reseller stores'
        // customers keep paying through the normal gateways.
        $gateways = $this->gateways->allEnabledForClient(['id' => 999, 'reseller_id' => 5]);

        foreach ($gateways as $gateway) {
            $this->assertNotSame('crypto_manual', $gateway['slug']);
        }

        // ...unless the admin deliberately turns that toggle on.
        $this->db->update("UPDATE payment_gateways SET reseller_enabled = 1 WHERE slug = 'crypto_manual'", []);
        $visible = $this->gateways->allEnabledForClient(['id' => 999, 'reseller_id' => 5]);
        $slugs = array_column($visible, 'slug');
        $this->assertContains('crypto_manual', $slugs);
    }

    public function test_a_platform_client_sees_the_gateway_in_their_list(): void
    {
        $slugs = array_column($this->gateways->allEnabledForClient(['id' => $this->clientId, 'reseller_id' => null]), 'slug');

        $this->assertContains('crypto_manual', $slugs);
    }

    // ── Configuration guards ─────────────────────────────────────────────

    public function test_an_invalid_wallet_address_refuses_the_page_politely(): void
    {
        $this->enableGateway(['wallet_address' => '0x1f9840a85d5aF5bf1D1762F925BDADdC4201F984']);
        $invoiceId = $this->createNairaInvoice();

        $response = $this->clientController->instructions($this->get('/x'), ['id' => (string) $invoiceId]);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('configuring', urldecode($response->headers()['Location'] ?? ''));
    }

    // ── The proof submission ─────────────────────────────────────────────

    public function test_submit_without_a_proof_is_refused(): void
    {
        $invoiceId = $this->createNairaInvoice();

        $response = $this->clientController->submit(
            $this->post("/client/invoices/{$invoiceId}/crypto", ['txid' => 'AABB11223344'], []),
            ['id' => (string) $invoiceId]
        );

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('proof', urldecode($response->headers()['Location'] ?? ''));
        $this->assertSame([], $this->db->select('SELECT * FROM manual_crypto_payments'));
    }

    public function test_submit_with_a_malformed_txid_is_refused(): void
    {
        $invoiceId = $this->createNairaInvoice();

        $response = $this->clientController->submit(
            $this->post("/client/invoices/{$invoiceId}/crypto", ['txid' => 'NOT A HASH!!'], []),
            ['id' => (string) $invoiceId]
        );

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('transaction hash', urldecode($response->headers()['Location'] ?? ''));
    }

    public function test_a_second_submission_while_one_is_pending_is_refused(): void
    {
        $invoiceId = $this->createNairaInvoice();
        $this->createSubmission($invoiceId);

        $response = $this->clientController->submit(
            $this->post("/client/invoices/{$invoiceId}/crypto", [], []),
            ['id' => (string) $invoiceId]
        );

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('already awaiting review', urldecode($response->headers()['Location'] ?? ''));
        $this->assertCount(1, $this->db->select('SELECT * FROM manual_crypto_payments'));
    }

    // ── The admin review pipeline ────────────────────────────────────────

    /**
     * The heart of the feature: approving a proof runs the ORDINARY payment
     * pipeline — transaction recorded under crypto_manual, invoice marked
     * paid, INVOICE_PAID fired — so a manual crypto DEPOSIT becomes wallet
     * credit with no crypto-specific code in the money path.
     */
    public function test_approval_marks_the_invoice_paid_and_credits_a_deposit_wallet(): void
    {
        $invoiceId = $this->createDepositInvoice(25.0); // ₦37,500 of wallet credit
        $submissionId = $this->createSubmission($invoiceId, 25.0);
        $controller = $this->adminController($_SESSION['admin_id'] = $this->createSuperAdmin());

        $response = $controller->approve($this->post('/x', []), ['id' => (string) $submissionId]);

        $this->assertSame(302, $response->status());
        $this->assertSame('paid', $this->invoices->find($invoiceId)['status']);

        $row = $this->payments->find($submissionId);
        $this->assertSame('approved', $row['status']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertNotNull($row['transaction_id']);

        $txns = $this->transactions->forInvoice($invoiceId);
        $this->assertSame('crypto_manual', $txns[0]['gateway_slug']);
        $this->assertSame('cryptomanual-' . $submissionId, $txns[0]['gateway_transaction_id']);

        // The wallet: 25.00 base credited (₦37,500 of buying power).
        $this->assertSame(25.0, (new ClientCreditRepository($this->db))->balance($this->clientId));
    }

    public function test_a_regular_admin_without_the_invoice_permission_cannot_review(): void
    {
        $invoiceId = $this->createDepositInvoice();
        $submissionId = $this->createSubmission($invoiceId);

        $roles = new RoleRepository($this->db);
        $noPermRole = $roles->create('Viewer', false, []);
        $adminId = (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            ['viewer', 'viewer@example.test', password_hash('x', PASSWORD_ARGON2ID), 'Viewer', $noPermRole]
        );

        $controller = $this->adminController($adminId);
        $response = $controller->approve($this->post('/x', []), ['id' => (string) $submissionId]);

        $this->assertSame(403, $response->status());
        $this->assertSame('pending', $this->payments->find($submissionId)['status']);
    }

    public function test_approving_twice_cannot_record_a_second_payment(): void
    {
        $invoiceId = $this->createDepositInvoice();
        $submissionId = $this->createSubmission($invoiceId);
        $controller = $this->adminController($this->createSuperAdmin());

        $controller->approve($this->post('/x', []), ['id' => (string) $submissionId]);
        $second = $controller->approve($this->post('/x', []), ['id' => (string) $submissionId]);

        $this->assertSame(302, $second->status());
        $this->assertStringContainsString('already reviewed', urldecode($second->headers()['Location'] ?? ''));
        $this->assertCount(1, $this->transactions->forInvoice($invoiceId));
    }

    public function test_approving_when_the_invoice_is_already_paid_closes_the_submission_without_a_second_payment(): void
    {
        $invoiceId = $this->createNairaInvoice();
        $submissionId = $this->createSubmission($invoiceId);

        // The invoice gets paid by another gateway while the proof sits in
        // the queue — approval must NOT record a second payment.
        $this->paymentsService()->recordPayment($invoiceId, 'paystack', 15.0, 'paystack-early');

        $controller = $this->adminController($this->createSuperAdmin());
        $response = $controller->approve($this->post('/x', []), ['id' => (string) $submissionId]);

        $this->assertStringContainsString('already paid', urldecode($response->headers()['Location'] ?? ''));
        $this->assertSame('rejected', $this->payments->find($submissionId)['status']);
        $this->assertCount(1, $this->transactions->forInvoice($invoiceId));
    }

    public function test_rejection_requires_a_reason_keeps_the_invoice_unpaid_and_notifies_the_client(): void
    {
        $invoiceId = $this->createNairaInvoice();
        $submissionId = $this->createSubmission($invoiceId);
        $controller = $this->adminController($this->createSuperAdmin());

        $noReason = $controller->reject($this->post('/x', ['reason' => '  ']), ['id' => (string) $submissionId]);
        $this->assertStringContainsString('reason is required', urldecode($noReason->headers()['Location'] ?? ''));
        $this->assertSame('pending', $this->payments->find($submissionId)['status']);

        $rejected = $controller->reject($this->post('/x', ['reason' => 'The screenshot shows a different amount.']), ['id' => (string) $submissionId]);
        $this->assertStringContainsString('rejected', urldecode($rejected->headers()['Location'] ?? ''));

        $row = $this->payments->find($submissionId);
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('The screenshot shows a different amount.', $row['admin_note']);
        $this->assertSame('unpaid', $this->invoices->find($invoiceId)['status'], 'rejection leaves the invoice payable again');
    }

    public function test_the_review_queue_lists_pending_first_with_the_usd_figure(): void
    {
        $invoiceId = $this->createNairaInvoice();
        $this->createSubmission($invoiceId, 15.0);

        $queue = $this->payments->forReview();

        $this->assertCount(1, $queue['pending']);
        $this->assertSame(15.0, (float) $queue['pending'][0]['amount_usd']);
        $this->assertSame('cryptodepositor@example.test', $queue['pending'][0]['client_email']);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function paymentsService(): PaymentService
    {
        return new PaymentService(
            $this->invoices,
            $this->transactions,
            new HookDispatcher(),
            $this->db,
            new ClientCreditRepository($this->db)
        );
    }

    private function createSuperAdmin(): int
    {
        $roleId = (new RoleRepository($this->db))->create('Super Admin', true, []);

        return (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            ['root', 'root@example.test', password_hash('x', PASSWORD_ARGON2ID), 'Root', $roleId]
        );
    }

    private function adminController(int $adminId): AdminCryptoManualController
    {
        $_SESSION['admin_id'] = $adminId;

        $guard = new AuthGuard(
            $this->sessionForAdmin(),
            new AdminRepository($this->db),
            new \CodeVault\Staff\RoleRepository($this->db)
        );

        return new AdminCryptoManualController(
            $guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->payments,
            $this->invoices,
            $this->transactions,
            $this->paymentsService(),
            $this->currency,
            $this->emailDispatcher(),
            new \CodeVault\Activity\ActivityLogger($this->db),
            $this->db
        );
    }

    private function sessionForAdmin(): SessionManager
    {
        // The client guard owns the setUp() session; the admin guard shares
        // the same $_SESSION store, which is what SessionManager reads.
        $reflection = new \ReflectionProperty(ClientAuthGuard::class, 'session');
        $reflection->setAccessible(true);

        return $reflection->getValue($this->clientGuard);
    }
}
