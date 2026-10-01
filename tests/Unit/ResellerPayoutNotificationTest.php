<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Notifications\NotificationEndpointController;
use CodeVault\Queue\SyncQueue;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerLedgerRepository;
use CodeVault\Reseller\ResellerLedgerService;
use CodeVault\Reseller\ResellerPayoutNotifier;
use CodeVault\Reseller\ResellerPayoutRepository;
use CodeVault\Reseller\ResellerPayoutService;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Staff\RoleRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Telling somebody there is a payout to release.
 *
 * Payouts are released by hand, so this notification is the only thing standing
 * between a settled balance and a reseller waiting indefinitely. That makes two
 * properties worth defending, and neither is about the email's wording:
 *
 *  - **The recipients are DERIVED from the permission that gates the queue, not
 *    hard-coded.** If somebody grants the payout queue to a finance role, that
 *    role must start being told. A hard-coded super-admin address would fail
 *    silently and permanently, because both halves would "work".
 *  - **A REFUSED request announces nothing.** The request path returns early on
 *    every refusal, so a notification that fires anyway — or fires before the
 *    debit is written — is the failure that trains recipients to ignore it.
 *
 * The last test guards a seam no compiler checks: a hook fired at a point with no
 * registered listener does nothing at all, quietly, while the firing code and the
 * notifier both look correct in isolation.
 */
final class ResellerPayoutNotificationTest extends DatabaseTestCase
{
    private AdminRepository $admins;
    private RoleRepository $roles;
    private ResellerPayoutService $service;
    private ResellerPayoutNotifier $notifier;
    private ResellerStoreRepository $stores;
    private ResellerLedgerService $accounts;
    private ClientRepository $clients;
    private CurrencyService $currency;
    private EmailDispatcher $mail;
    private int $resellerClientId;
    private int $customerId;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $configDir = sys_get_temp_dir() . '/codevault-payout-notify-' . uniqid();
        mkdir($configDir);
        $config = new Config($configDir);

        $this->clients = new ClientRepository($this->db);
        $settings = new SettingsRepository($this->db);
        $this->currency = new CurrencyService(new CurrencyRepository($this->db));
        $this->admins = new AdminRepository($this->db);
        $this->roles = new RoleRepository($this->db);

        $this->stores = new ResellerStoreRepository($this->db);
        $stores = new ResellerStoreService(
            $this->stores,
            new ResellerStoreLocator($this->stores, $config),
            new DomainVerifier()
        );

        $ledger = new ResellerLedgerRepository($this->db);
        $this->accounts = new ResellerLedgerService(
            $ledger,
            $this->stores,
            $this->clients,
            $this->currency,
            $settings
        );

        $this->mail = new EmailDispatcher(
            new EmailTemplateRepository($this->db),
            new EmailLogRepository($this->db),
            new SyncQueue()
        );

        $this->notifier = new ResellerPayoutNotifier(
            $this->mail,
            $this->admins,
            $this->clients,
            $this->stores,
            $this->currency,
            $config
        );

        $this->service = new ResellerPayoutService(
            new ResellerPayoutRepository($this->db),
            $ledger,
            $this->accounts,
            $this->currency,
            new HookDispatcher()
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

    // ------------------------------------------------------------- recipients

    public function test_every_admin_who_can_release_the_payout_is_told(): void
    {
        // Three admins, and the fixture asserts its own preconditions — a suite where
        // the "unrelated" admin accidentally holds the permission would report the
        // same green tick as one where the derivation works.
        $managerRole = $this->roles->create('Reseller Manager', false, [PermissionRegistry::RESELLERS_MANAGE]);
        $unrelatedRole = $this->roles->create('Ticket Desk', false, [PermissionRegistry::TICKETS_MANAGE]);
        $superRole = $this->roles->create('Boss', true, []);

        $this->admins->create('manager', 'manager@example.test', 'correct-horse-battery', 'Manager', $managerRole);
        $this->admins->create('desk', 'desk@example.test', 'correct-horse-battery', 'Desk', $unrelatedRole);
        $this->admins->create('boss', 'boss@example.test', 'correct-horse-battery', 'Boss', $superRole);

        $this->assertSame(
            ['boss@example.test', 'desk@example.test', 'manager@example.test'],
            $this->recipientEmails(),
            'the fixture itself must be wrong before this test means anything'
        );

        $this->notifier->requested($this->payoutRow($this->fundAndRequest()));

        $this->assertEqualsCanonicalizing(
            ['manager@example.test', 'boss@example.test'],
            $this->emailedAddresses(),
            'the queue permission grants access, and is_super_admin bypasses the matrix — both get told, nobody else does'
        );
    }

    public function test_an_admin_with_no_role_is_not_told(): void
    {
        // A role-less admin can open nothing, so telling them to go and release a
        // payout is noise that makes the notification less trustworthy — the exact
        // opposite of "tell whoever can act".
        $managerRole = $this->roles->create('Reseller Manager', false, [PermissionRegistry::RESELLERS_MANAGE]);
        $this->admins->create('manager', 'manager@example.test', 'correct-horse-battery', 'Manager', $managerRole);
        $this->admins->create('ghost', 'ghost@example.test', 'correct-horse-battery', 'Ghost', null);

        $this->notifier->requested($this->payoutRow($this->fundAndRequest()));

        $this->assertSame(['manager@example.test'], $this->emailedAddresses());
    }

    // ---------------------------------------------------------------- content

    public function test_the_email_carries_the_amount_the_currency_and_the_store(): void
    {
        $managerRole = $this->roles->create('Reseller Manager', false, [PermissionRegistry::RESELLERS_MANAGE]);
        $this->admins->create('manager', 'manager@example.test', 'correct-horse-battery', 'Manager', $managerRole);

        $payoutId = $this->fundAndRequest();
        $this->notifier->requested($this->payoutRow($payoutId));

        $row = $this->db->selectOne('SELECT subject FROM email_log ORDER BY id DESC LIMIT 1');

        $this->assertNotNull($row);
        $this->assertStringContainsString('Acme', (string) $row['subject'], 'the store is named so the admin knows whose money it is');
        $this->assertStringContainsString('100.00', (string) $row['subject'], 'the amount must survive into the subject, not just the body');
    }

    // ------------------------------------------------------------------ firing

    public function test_requesting_a_payout_fires_the_hook_the_notifier_hangs_off(): void
    {
        // Not a formality: HookDispatcher::fire() with nothing registered is a no-op,
        // so a hook point fired at a point nobody listens to produces NO error, NO
        // log line and NO notification — the payout simply goes unannounced.
        $hooks = new HookDispatcher();
        $seen = [];
        $hooks->register(HookPoints::RESELLER_PAYOUT_REQUESTED, function (array $payload) use (&$seen) {
            $seen[] = $payload;
        });

        $service = new ResellerPayoutService(
            new ResellerPayoutRepository($this->db),
            new ResellerLedgerRepository($this->db),
            $this->accounts,
            $this->currency,
            $hooks
        );

        $this->earn(100.0, 40);
        $result = $service->request($this->storeId);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $seen, 'a successful request must announce itself exactly once');
        $this->assertSame((int) $result['payout']['id'], (int) $seen[0]['payoutId']);
    }

    public function test_a_refused_request_announces_nothing(): void
    {
        $hooks = new HookDispatcher();
        $fired = 0;
        $hooks->register(HookPoints::RESELLER_PAYOUT_REQUESTED, function () use (&$fired) {
            $fired++;
        });

        $service = new ResellerPayoutService(
            new ResellerPayoutRepository($this->db),
            new ResellerLedgerRepository($this->db),
            $this->accounts,
            $this->currency,
            $hooks
        );

        // No money on the account at all: the request is refused.
        $result = $service->request($this->storeId);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $fired, 'nothing is waiting to be released, so nothing should be announced');
    }

    // ------------------------------------------------------- the unwired seam

    public function test_the_new_hook_is_offered_by_the_endpoint_picker_and_wired_in_the_kernel(): void
    {
        // Two halves that each look correct alone. The picker only offers events
        // that the Kernel actually dispatches (WIRED_EVENTS), so a hook that is
        // fired but missing from that list is unsubscribable — and a hook in that
        // list but never dispatched is a subscription that silently never runs.
        $picker = new \ReflectionClass(NotificationEndpointController::class);
        $wired = $picker->getConstant('WIRED_EVENTS');

        $this->assertIsArray($wired);
        $this->assertContains(
            HookPoints::RESELLER_PAYOUT_REQUESTED,
            $wired,
            'the picker must offer the event, or no Slack/webhook endpoint can subscribe to it'
        );

        $kernel = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Kernel.php');

        $this->assertStringContainsString(
            'HookPoints::RESELLER_PAYOUT_REQUESTED',
            $kernel,
            'the Kernel must register a listener, or the hook fires into nothing'
        );
        $this->assertStringContainsString(
            'ResellerPayoutNotifier',
            $kernel,
            'the listener is where the email to the admins happens'
        );
    }

    // -------------------------------------------------------------- fixtures

    /** Give the store a matured receipt, then request a payout. Returns the payout id. */
    private function fundAndRequest(): int
    {
        $this->earn(100.0, 40);
        $result = $this->service->request($this->storeId);

        $this->assertTrue($result['ok'], 'the fixture must produce a real request before anything is asserted about it');

        return (int) $result['payout']['id'];
    }

    private function earn(float $retail, int $daysAgo): void
    {
        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, reseller_id, status, total, cost_total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $this->storeId, 'active', $retail, round($retail * 0.8, 2), '2026-01-01 09:00:00', '2026-01-01 09:00:00']
        );

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->customerId, $orderId, 'paid', $retail, 0.0, $retail, '2026-01-01', '2026-01-01 09:00:00', '2026-01-01 09:00:00']
        );

        $this->accounts->accrueStoreReceipt(
            $invoiceId,
            (new \DateTimeImmutable('-' . $daysAgo . ' days'))->format('Y-m-d H:i:s')
        );
    }

    /** @return array<string, mixed> */
    private function payoutRow(int $payoutId): array
    {
        $row = (new ResellerPayoutRepository($this->db))->find($payoutId);

        $this->assertNotNull($row, 'the payout row must exist for the notifier to describe it');

        return $row;
    }

    /** @return array<int, string> */
    private function recipientEmails(): array
    {
        return array_map(
            static fn (array $a): string => (string) $a['email'],
            $this->admins->all()
        );
    }

    /** @return array<int, string> */
    private function emailedAddresses(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['to_email'],
            $this->db->select('SELECT to_email FROM email_log ORDER BY id')
        );
    }
}
