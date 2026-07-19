<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\VatNumberValidator;
use CodeVault\Billing\ViesVatLookupService;
use CodeVault\Clients\ClientAccountController;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Gdpr\GdprRequestRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\ClientSecurityAnswerRepository;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\SecurityQuestionModuleRepository;
use CodeVault\Modules\SecurityQuestionModuleService;
use CodeVault\Request;
use CodeVault\Security\RecoveryCodes;
use CodeVault\Security\Totp;
use CodeVault\Session\SessionManager;
use CodeVault\Support\App;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;

final class ClientAccountControllerTest extends DatabaseTestCase
{
    private ClientAccountController $controller;
    private ClientRepository $clients;
    private ClientAuthGuard $guard;
    private Totp $totp;
    private RecoveryCodes $recoveryCodes;
    private GdprRequestRepository $gdprRequests;
    private FakeHttpClient $vatHttp;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->clientId = $this->clients->create([
            'email' => 'accountholder@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Account',
            'last_name' => 'Holder',
        ]);

        $configDir = sys_get_temp_dir() . '/codevault-client-2fa-test-' . uniqid();
        mkdir($configDir);
        $_SESSION = [];
        $session = new SessionManager(new Config($configDir));
        $this->guard = new ClientAuthGuard($session, $this->clients);
        $this->guard->login($this->clients->find($this->clientId));

        $container = new Container();
        $container->instance(SessionManager::class, $session);
        App::setContainer($container);

        $this->totp = new Totp();
        $this->recoveryCodes = new RecoveryCodes();
        $this->gdprRequests = new GdprRequestRepository($this->db);

        $this->controller = new ClientAccountController(
            $this->guard,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->clients,
            $this->totp,
            $this->recoveryCodes,
            new Config(sys_get_temp_dir() . '/codevault-client-2fa-test-noenv-' . uniqid()),
            $this->gdprRequests,
            new SecurityQuestionModuleService(
                new ModuleManager(new HookDispatcher()),
                new SecurityQuestionModuleRepository($this->db),
                new ClientSecurityAnswerRepository($this->db)
            ),
            new VatNumberValidator(),
            new ViesVatLookupService($this->vatHttp = new FakeHttpClient())
        );
    }

    public function test_update_profile_rejects_an_email_already_used_by_another_client(): void
    {
        $this->clients->create([
            'email' => 'taken@example.test',
            'password' => 'whatever123',
            'first_name' => 'Other',
            'last_name' => 'Client',
        ]);

        $response = $this->controller->updateProfile($this->request([
            'email' => 'taken@example.test',
            'first_name' => 'Account',
            'last_name' => 'Holder',
        ]));

        $this->assertSame(200, $response->status());
        $client = $this->clients->find($this->clientId);
        $this->assertSame('accountholder@example.test', $client['email']);
    }

    public function test_update_profile_saves_contact_details(): void
    {
        $response = $this->controller->updateProfile($this->request([
            'email' => 'accountholder@example.test',
            'first_name' => 'Updated',
            'last_name' => 'Holder',
            'company_name' => 'Acme Co',
            'city' => 'Lagos',
        ]));

        $this->assertSame(200, $response->status());
        $client = $this->clients->find($this->clientId);
        $this->assertSame('Updated', $client['first_name']);
        $this->assertSame('Acme Co', $client['company_name']);
        $this->assertSame('Lagos', $client['city']);
    }

    public function test_update_password_rejects_wrong_current_password(): void
    {
        $response = $this->controller->updatePassword($this->request([
            'current_password' => 'wrong-password',
            'new_password' => 'brand-new-password',
        ]));

        $this->assertSame(200, $response->status());

        $client = $this->clients->find($this->clientId);
        $this->assertTrue(password_verify('correct-horse-battery', $client['password_hash']), 'password must be unchanged');
    }

    public function test_update_password_rejects_a_new_password_shorter_than_8_characters(): void
    {
        $response = $this->controller->updatePassword($this->request([
            'current_password' => 'correct-horse-battery',
            'new_password' => 'short',
        ]));

        $this->assertSame(200, $response->status());

        $client = $this->clients->find($this->clientId);
        $this->assertTrue(password_verify('correct-horse-battery', $client['password_hash']), 'password must be unchanged');
    }

    public function test_update_password_succeeds_with_correct_current_password(): void
    {
        $response = $this->controller->updatePassword($this->request([
            'current_password' => 'correct-horse-battery',
            'new_password' => 'brand-new-password',
        ]));

        $this->assertSame(200, $response->status());

        $client = $this->clients->find($this->clientId);
        $this->assertTrue(password_verify('brand-new-password', $client['password_hash']));
    }

    public function test_enable_stores_a_pending_secret_without_enabling_two_factor(): void
    {
        $response = $this->controller->enable($this->request());

        $this->assertSame(200, $response->status());

        $client = $this->clients->find($this->clientId);
        $this->assertNotNull($client['two_factor_secret']);
        $this->assertSame(0, (int) $client['two_factor_enabled']);
    }

    public function test_confirm_with_correct_code_enables_two_factor(): void
    {
        $this->controller->enable($this->request());
        $secret = (string) $this->clients->find($this->clientId)['two_factor_secret'];

        $response = $this->controller->confirm($this->request(['code' => $this->totp->currentCode($secret)]));

        $this->assertSame(302, $response->status());
        $client = $this->clients->find($this->clientId);
        $this->assertSame(1, (int) $client['two_factor_enabled']);
    }

    public function test_disable_with_correct_password_clears_two_factor_state(): void
    {
        $this->controller->enable($this->request());
        $secret = (string) $this->clients->find($this->clientId)['two_factor_secret'];
        $this->controller->confirm($this->request(['code' => $this->totp->currentCode($secret)]));

        $response = $this->controller->disable($this->request(['password' => 'correct-horse-battery']));

        $this->assertSame(302, $response->status());
        $client = $this->clients->find($this->clientId);
        $this->assertSame(0, (int) $client['two_factor_enabled']);
        $this->assertNull($client['two_factor_secret']);
    }

    public function test_request_export_creates_a_pending_request_and_is_idempotent(): void
    {
        $this->controller->requestExport($this->request());
        $this->controller->requestExport($this->request());

        $requests = $this->gdprRequests->forClient($this->clientId);
        $this->assertCount(1, $requests, 'a second click must not create a duplicate pending request');
        $this->assertSame('export', $requests[0]['type']);
        $this->assertSame('pending', $requests[0]['status']);
    }

    public function test_request_erasure_creates_a_pending_request_and_is_idempotent(): void
    {
        $this->controller->requestErasure($this->request());
        $this->controller->requestErasure($this->request());

        $requests = $this->gdprRequests->forClient($this->clientId);
        $this->assertCount(1, $requests);
        $this->assertSame('erasure', $requests[0]['type']);
    }

    public function test_download_export_rejects_a_request_that_is_not_completed(): void
    {
        $id = $this->gdprRequests->create($this->clientId, 'export');

        $response = $this->controller->downloadExport($this->getRequest(), ['id' => (string) $id]);

        $this->assertSame(404, $response->status());
    }

    public function test_download_export_rejects_another_clients_completed_export(): void
    {
        $otherClientId = $this->clients->create([
            'email' => 'other@example.test',
            'password' => 'whatever123',
            'first_name' => 'Other',
            'last_name' => 'Client',
        ]);
        $id = $this->gdprRequests->create($otherClientId, 'export');
        $this->gdprRequests->markCompleted($id, 1, '{"profile":{}}', null);

        $response = $this->controller->downloadExport($this->getRequest(), ['id' => (string) $id]);

        $this->assertSame(404, $response->status());
    }

    public function test_download_export_streams_the_export_json_for_the_owning_client(): void
    {
        $id = $this->gdprRequests->create($this->clientId, 'export');
        $this->gdprRequests->markCompleted($id, 1, '{"profile":{"email":"accountholder@example.test"}}', null);

        $response = $this->controller->downloadExport($this->getRequest(), ['id' => (string) $id]);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('accountholder@example.test', $response->body());
    }

    /** @param array<string, mixed> $body */
    private function request(array $body = []): Request
    {
        return new Request([], $body, ['REQUEST_METHOD' => 'POST'], []);
    }

    private function getRequest(): Request
    {
        return new Request([], [], ['REQUEST_METHOD' => 'GET'], []);
    }
}
