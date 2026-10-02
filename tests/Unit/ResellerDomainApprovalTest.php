<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;

/**
 * Custom-domain approval (migration 0198).
 *
 * Submitting a domain is a REQUEST, and an admin decides it. The rules live in
 * ResellerStoreRepository, so this drives them there rather than through a
 * controller — including the two that are easy to get wrong:
 *
 *  - a decision is guarded on `domain_status = 'pending'`, so two admins deciding
 *    at the same moment cannot both succeed and the second is TOLD rather than
 *    silently overwriting the first;
 *  - refusing CLEARS the DNS verification, so "refused" actually stops a domain
 *    being served instead of being a label with no effect.
 *
 * The second is the one worth a companion test: refusing a domain that was
 * already verified is the case where a status-only implementation would look
 * correct and leave the store being served at a hostname we have just refused.
 */
final class ResellerDomainApprovalTest extends DatabaseTestCase
{
    private ResellerStoreRepository $stores;
    private int $storeId;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $clients = new ClientRepository($this->db);

        $clientId = $clients->create([
            'email' => 'store-owner-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'first_name' => 'Store',
            'last_name' => 'Owner',
        ]);

        $this->adminId = (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'reviewer-' . uniqid(),
                'reviewer-' . uniqid() . '@example.test',
                'x',
                'Reviewer',
                1,
                '2026-01-01 00:00:00',
                '2026-01-01 00:00:00',
            ]
        );

        $this->stores = new ResellerStoreRepository($this->db);
        $this->storeId = $this->stores->create($clientId, 'acme-' . substr(uniqid(), -6), 'Acme');
    }

    public function test_submitting_a_domain_creates_a_pending_request(): void
    {
        $this->submit('shop.example.com');

        $store = $this->stores->find($this->storeId);

        $this->assertSame('pending', (string) $store['domain_status']);
        $this->assertNotNull($store['domain_requested_at']);
        $this->assertNull($store['domain_reviewed_at']);
        $this->assertSame('shop.example.com', (string) $store['custom_domain']);
    }

    public function test_approving_records_the_decision_and_who_made_it(): void
    {
        $this->submit('shop.example.com');

        $this->assertTrue($this->stores->approveDomain($this->storeId, $this->adminId, 'Checked with the customer'));

        $store = $this->stores->find($this->storeId);

        $this->assertSame('approved', (string) $store['domain_status']);
        $this->assertSame($this->adminId, (int) $store['domain_reviewed_by']);
        $this->assertNotNull($store['domain_reviewed_at']);
        $this->assertSame('Checked with the customer', (string) $store['domain_review_note']);
    }

    public function test_a_second_decision_cannot_overwrite_the_first(): void
    {
        $this->submit('shop.example.com');
        $this->stores->approveDomain($this->storeId, $this->adminId, null);

        // The status is no longer pending, so the guarded UPDATE matches nothing
        // and the caller is told — rather than the first decision being replaced.
        $this->assertFalse($this->stores->rejectDomain($this->storeId, $this->adminId, 'Changed my mind'));

        $this->assertSame('approved', (string) $this->stores->find($this->storeId)['domain_status']);
    }

    public function test_refusing_stores_the_reason_and_stops_the_domain_being_served(): void
    {
        $this->submit('shop.example.com');
        // Already proved control: this is the state a status-only implementation
        // would get wrong, leaving the store served at a refused hostname.
        $this->stores->markDomainVerified($this->storeId, 'txt');

        $this->assertTrue($this->stores->rejectDomain($this->storeId, $this->adminId, 'Not this customer\'s domain'));

        $store = $this->stores->find($this->storeId);

        $this->assertSame('rejected', (string) $store['domain_status']);
        $this->assertSame('Not this customer\'s domain', (string) $store['domain_review_note']);
        $this->assertNull($store['domain_verified_at'], 'a refused domain must not keep its serving proof');
        $this->assertNull($store['domain_verification_method']);
    }

    public function test_resubmitting_after_a_refusal_returns_to_pending_and_clears_the_reason(): void
    {
        $this->submit('shop.example.com');
        $this->stores->rejectDomain($this->storeId, $this->adminId, 'Wrong domain');

        // The reseller corrects it and saves again.
        $this->submit('shop.correct.example.com');

        $store = $this->stores->find($this->storeId);

        $this->assertSame('pending', (string) $store['domain_status']);
        $this->assertNull($store['domain_review_note'], 'the old refusal reason must not linger');
        $this->assertNull($store['domain_reviewed_at']);
        $this->assertNull($store['domain_reviewed_by']);
        $this->assertSame('shop.correct.example.com', (string) $store['custom_domain']);
    }

    public function test_releasing_the_domain_resets_the_review_state(): void
    {
        $this->submit('shop.example.com');
        $this->stores->approveDomain($this->storeId, $this->adminId, null);

        $this->submit(null);

        $store = $this->stores->find($this->storeId);

        $this->assertNull($store['custom_domain']);
        $this->assertSame('none', (string) $store['domain_status']);
        $this->assertNull($store['domain_requested_at']);
    }

    public function test_a_request_appears_in_the_admin_queue(): void
    {
        $this->submit('shop.example.com');

        $pending = $this->stores->pendingDomainRequests();

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $pending);
        $this->assertContains($this->storeId, $ids);

        // And stops appearing once decided.
        $this->stores->approveDomain($this->storeId, $this->adminId, null);

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $this->stores->pendingDomainRequests());
        $this->assertNotContains($this->storeId, $ids);
    }

    /**
     * A route and a controller method are joined only by a string, so nothing
     * checks that the review screen is reachable at all. Same guard as the payout
     * routes carry.
     */
    public function test_the_domain_routes_are_declared_for_their_controller(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/reseller.php');

        foreach (['index', 'approve', 'reject', 'saveSettings', 'unprovision', 'provisionNow', 'runDiagnostic'] as $method) {
            $this->assertStringContainsString(
                'AdminResellerDomainController::class, ' . "'" . $method . "'",
                $routes,
                "No route is declared for AdminResellerDomainController::{$method}()."
            );
        }
    }

    /**
     * A view and the controller that fills it are deployed as SEPARATE files, and
     * this happened for real: the template shipped, the controller did not, and a
     * live admin page printed `Undefined variable $panelCheck` three times.
     *
     * Two things have to hold in that state, and they pull in different
     * directions. No PHP diagnostics — a warning printed before a `header()`
     * breaks the redirect, which turns one missing array into "the feature is
     * broken". And no ALL-CLEAR: an empty outstanding list means "nothing of ours
     * is left on the hosting panel", so a MISSING list rendered as empty would
     * report the all-clear about hostnames that are still answering out there.
     * The reassuring reading of a missing list is the wrong one.
     */
    public function test_the_page_neither_warns_nor_claims_all_clear_when_the_outstanding_list_is_missing(): void
    {
        $configDir = sys_get_temp_dir() . '/codevault-domain-page-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];

        $session = new SessionManager(new Config($configDir));

        $container = new Container();
        $container->instance(\CodeVault\Database::class, $this->db);
        $container->instance(SettingsRepository::class, new SettingsRepository($this->db));
        $container->instance(SessionManager::class, $session);
        // The page renders forms, so csrf_field() has to resolve a token.
        $container->bind(CsrfToken::class);
        App::setContainer($container);

        $diagnostics = [];
        set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        try {
            $html = (new View(dirname(__DIR__, 2) . '/resources/views'))->render('reseller.admin-domains', [
                'pending' => [],
                'decided' => [],
                'servers' => [],
                'settings' => ['mode' => 'off', 'server_id' => '', 'account' => '', 'docroot' => ''],
                'notice' => null,
                'error' => null,
                // $outstanding and $panelCheck deliberately omitted — this is the
                // older-controller deployment.
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $diagnostics, 'The view must not raise diagnostics when a variable is missing.');
        $this->assertStringContainsString('could not be loaded', $html);
        $this->assertStringNotContainsString('Nothing is outstanding', $html, 'a missing list must not read as the all-clear.');
    }

    private function submit(?string $domain): void
    {
        $this->stores->setCustomDomain($this->storeId, $domain, DomainVerifier::newToken());
    }
}
