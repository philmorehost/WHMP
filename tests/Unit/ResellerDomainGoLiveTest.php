<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Reseller\DomainVerificationJob;
use CodeVault\Reseller\DomainVerifier;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\ResellerStoreService;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Taking a store live on its own domain.
 *
 * Three properties are being defended, and the middle one is the reason this
 * class exists:
 *
 *  1. **A claim goes live on its own** once DNS answers, without the reseller
 *     having to press anything a second time.
 *  2. **A DNS failure never takes a verified domain offline.** Verification is
 *     not re-examined once proved: a transient resolver failure at 03:00 must
 *     not black out a working storefront, and the risk that verification guards
 *     against does not come back after it has been settled.
 *  3. **An admin override is visible as an override.** It is recorded as
 *     'manual' on the store, never as a DNS proof, and a later real proof
 *     replaces it.
 *
 * The server-side half — the domain actually resolving here and holding a TLS
 * certificate — is reported as a manual step rather than pretended to be
 * tracked, because nothing in this application can observe or perform it.
 */
final class ResellerDomainGoLiveTest extends DatabaseTestCase
{
    private const PLATFORM_URL = 'https://platform.test';

    private ResellerStoreRepository $stores;
    private ResellerStoreLocator $locator;
    private ResellerStoreService $service;
    private ClientRepository $clients;
    private DomainVerificationJob $job;

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $txtAnswers = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $cnameAnswers = [];

    private int $clientId;
    private int $storeId;
    private string $configDir;
    private string|false $previousAppUrl;
    private string|false $previousAppName;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        // The platform host comes from APP_URL, and Config::loadEnv() will not
        // override an already-set process variable — so set it explicitly and
        // put the originals back in tearDown(), or this class would silently
        // decide the platform host for every suite that runs after it.
        $this->previousAppUrl = getenv('APP_URL');
        $this->previousAppName = getenv('APP_NAME');
        putenv('APP_URL=' . self::PLATFORM_URL);
        $_ENV['APP_URL'] = self::PLATFORM_URL;
        putenv('APP_NAME=Platform');
        $_ENV['APP_NAME'] = 'Platform';

        $this->configDir = sys_get_temp_dir() . '/codevault-golive-' . uniqid();
        mkdir($this->configDir);

        $this->stores = new ResellerStoreRepository($this->db);
        $this->locator = new ResellerStoreLocator($this->stores, new Config($this->configDir));
        $this->clients = new ClientRepository($this->db);

        // DNS answers are read at call time, so a test can change them between
        // the claim, the verification and a later failure.
        $this->service = new ResellerStoreService(
            $this->stores,
            $this->locator,
            new DomainVerifier(
                fn (string $host): array => $this->txtAnswers[$host] ?? [],
                fn (string $host): array => $this->cnameAnswers[$host] ?? []
            )
        );

        $this->job = new DomainVerificationJob($this->stores, $this->service, new ActivityLogger($this->db));

        $this->clientId = $this->clients->create([
            'email' => 'storeowner@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Store',
            'last_name' => 'Owner',
        ]);

        $this->storeId = (int) $this->service->openForClient($this->clientId, 'Acme')['store']['id'];
    }

    protected function tearDown(): void
    {
        if ($this->previousAppUrl !== false) {
            putenv('APP_URL=' . $this->previousAppUrl);
            $_ENV['APP_URL'] = $this->previousAppUrl;
        } else {
            putenv('APP_URL');
            unset($_ENV['APP_URL']);
        }

        if ($this->previousAppName !== false) {
            putenv('APP_NAME=' . $this->previousAppName);
            $_ENV['APP_NAME'] = $this->previousAppName;
        } else {
            putenv('APP_NAME');
            unset($_ENV['APP_NAME']);
        }

        parent::tearDown();
    }

    // --- the nightly check ------------------------------------------------

    public function test_the_nightly_job_verifies_a_claim_once_dns_answers(): void
    {
        $this->claimAndAnswerDns('shop.example.com');
        $this->assertFalse($this->isVerified());

        $this->job->handle();

        $this->assertTrue($this->isVerified());
        $this->assertSame('txt', $this->method());
    }

    public function test_the_nightly_job_leaves_a_claim_alone_while_dns_has_nothing(): void
    {
        $this->service->claimDomain($this->storeId, 'shop.example.com');

        $this->job->handle();

        $this->assertFalse($this->isVerified());
        $this->assertNull($this->method());
    }

    public function test_the_nightly_job_ignores_a_store_with_no_domain(): void
    {
        $this->job->handle();

        $this->assertFalse($this->isVerified());
    }

    public function test_a_cname_pointing_at_the_platform_verifies_too(): void
    {
        $this->service->claimDomain($this->storeId, 'shop.example.com');
        $this->cnameAnswers['shop.example.com'] = [['target' => 'platform.test']];

        $this->job->handle();

        $this->assertTrue($this->isVerified());
        $this->assertSame('cname', $this->method());
    }

    public function test_the_nightly_job_records_what_it_verified(): void
    {
        $this->claimAndAnswerDns('shop.example.com');

        $this->job->handle();

        $rows = $this->db->select(
            "SELECT * FROM activity_log WHERE action = 'reseller.store.domain_verified' AND subject_id = ?",
            [$this->storeId]
        );

        $this->assertCount(1, $rows);
        $this->assertSame('system', $rows[0]['actor_type']);
        $this->assertStringContainsString('shop.example.com', (string) $rows[0]['description']);
    }

    public function test_a_verified_store_is_not_examined_again(): void
    {
        $this->claimAndAnswerDns('shop.example.com');
        $this->job->handle();

        $this->assertSame([], $this->stores->pendingDomainClaims());
    }

    // --- the safety property ---------------------------------------------

    public function test_a_dns_failure_never_takes_a_verified_domain_offline(): void
    {
        $this->claimAndAnswerDns('shop.example.com');
        $this->job->handle();
        $this->assertTrue($this->isVerified());

        // The next day the domain answers nothing at all — a resolver outage, a
        // broken record, a provider hiccup. None of that may black out a store
        // that is already serving customers.
        $this->txtAnswers = [];
        $this->cnameAnswers = [];

        $this->job->handle();
        $this->assertTrue($this->isVerified(), 'The nightly job must not un-verify a live domain.');

        // And even a direct re-check reports the failure without revoking it.
        $result = $this->service->verifyDomain($this->storeId);

        $this->assertFalse($result['verified']);
        $this->assertTrue($this->isVerified(), 'A failed re-check must leave the verification standing.');
    }

    public function test_moving_to_a_new_domain_clears_the_old_verification(): void
    {
        $this->claimAndAnswerDns('shop.example.com');
        $this->job->handle();
        $this->assertTrue($this->isVerified());

        $this->service->claimDomain($this->storeId, 'store.example.com');

        // Verification applies to a DOMAIN, so a different one has to earn its
        // own — otherwise a reseller could verify a domain they control and then
        // point the store at one they do not.
        $this->assertFalse($this->isVerified());
        $this->assertSame('store.example.com', $this->store()['custom_domain']);
    }

    // --- the admin override ----------------------------------------------

    public function test_an_override_verifies_without_dns_and_says_so(): void
    {
        $this->service->claimDomain($this->storeId, 'shop.example.com');

        $result = $this->service->overrideDomainVerification($this->storeId, true);

        $this->assertTrue($result['success']);
        $this->assertTrue($this->isVerified());
        $this->assertSame('manual', $this->method());
    }

    public function test_removing_a_verification_keeps_the_domain_claimed(): void
    {
        $this->claimAndAnswerDns('shop.example.com');
        $this->service->verifyDomain($this->storeId);
        $this->assertTrue($this->isVerified());

        $result = $this->service->overrideDomainVerification($this->storeId, false);

        $this->assertTrue($result['success']);
        $this->assertFalse($this->isVerified());
        $this->assertNull($this->method());
        // Still reserved to this store: it just stops being served.
        $this->assertSame('shop.example.com', $this->store()['custom_domain']);
    }

    public function test_a_real_proof_replaces_a_manual_override(): void
    {
        $this->service->claimDomain($this->storeId, 'shop.example.com');
        $this->service->overrideDomainVerification($this->storeId, true);
        $this->assertSame('manual', $this->method());

        // The provider finally serves the record, for the token we already
        // issued. The store should end up recording the real proof, not the
        // override that stood in for it.
        $this->answerDns('shop.example.com');
        $this->service->verifyDomain($this->storeId);

        $this->assertSame('txt', $this->method());
    }

    public function test_an_override_refuses_a_store_that_has_no_domain(): void
    {
        $result = $this->service->overrideDomainVerification($this->storeId, true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not claimed a domain', (string) $result['error']);
        $this->assertFalse($this->isVerified());
    }

    // --- the checklist ---------------------------------------------------

    public function test_the_checklist_reports_the_server_steps_as_manual_not_done(): void
    {
        $this->claimAndAnswerDns('shop.example.com');
        $this->job->handle();

        $byKey = $this->checklistByKey();

        $this->assertTrue($byKey['domain_claimed']['done']);
        $this->assertTrue($byKey['dns_proof']['done']);
        $this->assertTrue($byKey['store_active']['done']);

        // Claimed as outside this application rather than silently "done" —
        // the platform cannot see whether DNS points here, let alone issue a
        // certificate.
        foreach (['dns_points_here', 'tls_certificate'] as $key) {
            $this->assertNull($byKey[$key]['done'], $key . ' must not claim to be done.');
            $this->assertTrue($byKey[$key]['manual'], $key . ' must be marked manual.');
            $this->assertStringContainsString('shop.example.com', (string) $byKey[$key]['detail']);
        }
    }

    public function test_the_checklist_tells_an_unverified_store_which_record_to_add(): void
    {
        $this->service->claimDomain($this->storeId, 'shop.example.com');

        $proof = $this->checklistByKey()['dns_proof'];

        $this->assertFalse($proof['done']);
        $this->assertStringContainsString('_codevault-verify.shop.example.com', (string) $proof['detail']);
        $this->assertStringContainsString('codevault-store-verify=', (string) $proof['detail']);
    }

    public function test_the_checklist_calls_a_forced_domain_forced(): void
    {
        $this->service->claimDomain($this->storeId, 'shop.example.com');
        $this->service->overrideDomainVerification($this->storeId, true);

        $proof = $this->checklistByKey()['dns_proof'];

        $this->assertTrue($proof['done']);
        $this->assertStringContainsString('Forced by an administrator', (string) $proof['detail']);
    }

    public function test_a_suspended_store_is_shown_as_not_switched_on(): void
    {
        $this->service->claimDomain($this->storeId, 'shop.example.com');
        $this->service->setStatus($this->storeId, 'suspended');

        $step = $this->checklistByKey()['store_active'];

        $this->assertFalse($step['done']);
    }

    // --- seams -----------------------------------------------------------

    public function test_the_job_is_registered_in_cron(): void
    {
        // The scheduler and the job are joined by a string no compiler checks.
        $cron = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/cron.php');

        $this->assertStringContainsString('DomainVerificationJob::class', $cron);
    }

    public function test_the_override_action_is_routed(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/reseller.php');

        $this->assertStringContainsString(
            "AdminResellerController::class, 'overrideStoreDomain'",
            $routes
        );
    }

    // --- helpers ---------------------------------------------------------

    /** Claims a domain AND makes DNS answer with the record that proves it. */
    private function claimAndAnswerDns(string $domain): void
    {
        $this->service->claimDomain($this->storeId, $domain);
        $this->answerDns($domain);
    }

    /**
     * Makes DNS answer for the token the store is CURRENTLY holding.
     *
     * Deliberately separate from claimAndAnswerDns(): re-claiming a domain
     * re-issues the token and clears the verified mark, so a test that wanted to
     * prove a manual override is upgraded by a real proof has to add the record
     * without re-claiming, or it would be testing a fresh verification instead.
     */
    private function answerDns(string $domain): void
    {
        $this->txtAnswers['_codevault-verify.' . $domain] = [
            ['txt' => 'codevault-store-verify=' . $this->store()['domain_verification_token']],
        ];
    }

    /** @return array<string, mixed> */
    private function store(): array
    {
        return (array) $this->stores->find($this->storeId);
    }

    private function isVerified(): bool
    {
        return ($this->store()['domain_verified_at'] ?? null) !== null;
    }

    private function method(): ?string
    {
        $method = $this->store()['domain_verification_method'] ?? null;

        return $method === null ? null : (string) $method;
    }

    /** @return array<string, array<string, mixed>> */
    private function checklistByKey(): array
    {
        $byKey = [];

        foreach ($this->service->goLiveChecklist($this->store()) as $step) {
            $byKey[(string) $step['key']] = $step;
        }

        return $byKey;
    }
}
