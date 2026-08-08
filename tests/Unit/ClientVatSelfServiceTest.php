<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\VatNumberValidator;
use CodeVault\Billing\ViesVatLookupService;
use CodeVault\Clients\ClientAccountController;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientAuthManager;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Gdpr\GdprRequestRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ClientSecurityAnswerRepository;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\SecurityQuestionModuleRepository;
use CodeVault\Modules\SecurityQuestionModuleService;
use CodeVault\Request;
use CodeVault\Security\AccountLockRepository;
use CodeVault\Security\BruteGuard;
use CodeVault\Security\CountryRuleRepository;
use CodeVault\Security\IpRuleRepository;
use CodeVault\Security\LoginAttemptRepository;
use CodeVault\Security\NullGeoIpResolver;
use CodeVault\Security\PhpassHasher;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;

/**
 * R30 — extends R22's admin-only VAT number entry to client self-service:
 * a `country`/`vat_number` pair collectable at registration, editable on
 * the client account page, and verifiable there via the same real VIES
 * lookup the admin action uses (`VatLookupService`), scoped to the
 * authenticated client's own record only.
 */
final class ClientVatSelfServiceTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private FakeHttpClient $vatHttp;
    private ClientAccountController $controller;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->clientId = $this->clients->create([
            'email' => 'vatclient@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Vat',
            'last_name' => 'Client',
            'country' => 'IE',
            'vat_number' => '6388047V',
        ]);

        $configDir = sys_get_temp_dir() . '/codevault-client-vat-test-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $guard = new ClientAuthGuard($session, $this->clients);
        $guard->login($this->clients->find($this->clientId));

        $container = new Container();
        $container->instance(SessionManager::class, $session);
        $container->instance(Database::class, $this->db);
        App::setContainer($container);

        $this->vatHttp = new FakeHttpClient();

        $this->controller = new ClientAccountController(
            $guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->clients,
            new Totp(),
            new RecoveryCodes(),
            new Config(sys_get_temp_dir() . '/codevault-client-vat-test-noenv-' . uniqid()),
            new GdprRequestRepository($this->db),
            new SecurityQuestionModuleService(
                new ModuleManager(new HookDispatcher()),
                new SecurityQuestionModuleRepository($this->db),
                new ClientSecurityAnswerRepository($this->db)
            ),
            new VatNumberValidator(),
            new ViesVatLookupService($this->vatHttp),
            new PhpassHasher()
        );
    }

    // --- ClientAuthManager::register() ---------------------------------------

    public function test_register_persists_an_optional_country_and_vat_number(): void
    {
        $bruteGuard = new BruteGuard(
            new LoginAttemptRepository($this->db),
            new IpRuleRepository($this->db),
            new CountryRuleRepository($this->db),
            new AccountLockRepository($this->db),
            new NullGeoIpResolver(),
            new HookDispatcher(),
        );
        $auth = new ClientAuthManager($this->clients, $bruteGuard, new SettingsRepository($this->db), new PhpassHasher());

        $result = $auth->register('newbiz@example.test', 'correct-horse-battery', 'New', 'Biz', '127.0.0.1', 'DE', 'DE123456789');

        $this->assertTrue($result['success']);
        $this->assertSame('DE', $result['client']['country']);
        $this->assertSame('DE123456789', $result['client']['vat_number']);
    }

    public function test_register_leaves_country_and_vat_number_null_when_omitted(): void
    {
        $bruteGuard = new BruteGuard(
            new LoginAttemptRepository($this->db),
            new IpRuleRepository($this->db),
            new CountryRuleRepository($this->db),
            new AccountLockRepository($this->db),
            new NullGeoIpResolver(),
            new HookDispatcher(),
        );
        $auth = new ClientAuthManager($this->clients, $bruteGuard, new SettingsRepository($this->db), new PhpassHasher());

        $result = $auth->register('noaddress@example.test', 'correct-horse-battery', 'No', 'Address', '127.0.0.1');

        $this->assertTrue($result['success']);
        $this->assertNull($result['client']['country']);
        $this->assertNull($result['client']['vat_number']);
    }

    // --- ClientAccountController::updateProfile() — vat_number persistence ---

    public function test_update_profile_persists_a_new_vat_number(): void
    {
        $response = $this->controller->updateProfile($this->profileRequest(['vat_number' => 'IE6388047V']));

        $this->assertSame(200, $response->status());
        $client = $this->clients->find($this->clientId);
        $this->assertSame('IE6388047V', $client['vat_number']);
    }

    public function test_update_profile_clears_a_prior_verification_when_the_vat_number_changes(): void
    {
        $this->clients->recordVatVerification($this->clientId, true, 'Original Corp');
        $this->assertNotNull($this->clients->find($this->clientId)['vat_verified_at']);

        $this->controller->updateProfile($this->profileRequest(['vat_number' => 'DE999999999']));

        $client = $this->clients->find($this->clientId);
        $this->assertSame('DE999999999', $client['vat_number']);
        $this->assertNull($client['vat_verified_at']);
        $this->assertNull($client['vat_verified_valid']);
        $this->assertNull($client['vat_verified_name']);
    }

    public function test_update_profile_keeps_a_prior_verification_when_the_vat_number_is_unchanged(): void
    {
        $this->clients->recordVatVerification($this->clientId, true, 'Original Corp');

        // Same vat_number as already stored ('6388047V') and same country.
        $this->controller->updateProfile($this->profileRequest(['vat_number' => '6388047V']));

        $client = $this->clients->find($this->clientId);
        $this->assertNotNull($client['vat_verified_at']);
        $this->assertSame('Original Corp', $client['vat_verified_name']);
    }

    // --- ClientAccountController::verifyVat() -----------------------------------

    public function test_verify_vat_errors_when_no_country_or_vat_number_is_set(): void
    {
        $this->clients->updateContactDetails($this->clientId, $this->baseFields(['country' => null, 'vat_number' => null]));

        $response = $this->controller->verifyVat($this->getRequest());

        $this->assertStringContainsString('Set a country and VAT number first', $response->body());
        $this->assertCount(0, $this->vatHttp->requests, 'no live VIES call should happen without a country+number');
    }

    public function test_verify_vat_rejects_a_structurally_invalid_number_without_calling_vies(): void
    {
        $this->clients->updateContactDetails($this->clientId, $this->baseFields(['country' => 'DE', 'vat_number' => 'not-a-vat-number']));

        $response = $this->controller->verifyVat($this->getRequest());

        $this->assertStringContainsString('look like a valid VAT number', $response->body());
        $this->assertCount(0, $this->vatHttp->requests, 'a format-invalid number should never spend a live VIES call');
    }

    public function test_verify_vat_records_a_valid_result_from_vies(): void
    {
        $this->vatHttp->respondWith(200, json_encode(['userError' => 'VALID', 'name' => 'Acme Ireland Ltd']));

        $response = $this->controller->verifyVat($this->getRequest());

        $this->assertStringContainsString('VAT number verified', $response->body());
        $client = $this->clients->find($this->clientId);
        $this->assertSame(1, (int) $client['vat_verified_valid']);
        $this->assertSame('Acme Ireland Ltd', $client['vat_verified_name']);
    }

    public function test_verify_vat_records_an_invalid_result_from_vies(): void
    {
        $this->vatHttp->respondWith(200, json_encode(['userError' => 'INVALID']));

        $response = $this->controller->verifyVat($this->getRequest());

        $this->assertStringContainsString('not valid', $response->body());
        $client = $this->clients->find($this->clientId);
        $this->assertSame(0, (int) $client['vat_verified_valid']);
    }

    public function test_verify_vat_does_not_persist_anything_when_vies_is_unreachable(): void
    {
        $this->vatHttp->respondWith(0, '');

        $response = $this->controller->verifyVat($this->getRequest());

        $this->assertStringContainsString('Could not reach', $response->body());
        $client = $this->clients->find($this->clientId);
        $this->assertNull($client['vat_verified_at']);
    }

    /** @param array<string, mixed> $overrides */
    private function baseFields(array $overrides = []): array
    {
        $client = $this->clients->find($this->clientId);

        return array_merge([
            'email' => $client['email'],
            'first_name' => $client['first_name'],
            'last_name' => $client['last_name'],
            'company_name' => $client['company_name'],
            'address1' => $client['address1'],
            'address2' => $client['address2'],
            'city' => $client['city'],
            'state' => $client['state'],
            'postcode' => $client['postcode'],
            'country' => $client['country'],
            'vat_number' => $client['vat_number'],
            'phone' => $client['phone'],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function profileRequest(array $overrides): Request
    {
        $client = $this->clients->find($this->clientId);

        $body = array_merge([
            'email' => $client['email'],
            'first_name' => $client['first_name'],
            'last_name' => $client['last_name'],
            'address1' => $client['address1'] ?: '1 Test Street',
            'city' => $client['city'] ?: 'Lagos',
            'postcode' => $client['postcode'] ?: '100001',
            'phone' => $client['phone'] ?: '+2348000000000',
            'country' => $client['country'],
            'vat_number' => $client['vat_number'],
        ], $overrides);

        return new Request([], $body, ['REQUEST_METHOD' => 'POST'], []);
    }

    private function getRequest(): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => 'GET'], []);
    }
}
