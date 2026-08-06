<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainController;
use CodeVault\Domains\DomainRepository;
use CodeVault\Domains\RegistrarRepository;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\RegistrarModule;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Staff\RoleRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Fixtures\FakeRegistrarModule;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The domain manage page lets an admin re-trigger registration for a domain
 * that was paid for but failed to register at the registrar (the recovery
 * path for a stuck `pending` domain with a provisioning_error). The optional
 * registrar selection re-points the domain first — mirroring the
 * updateRegistrar recovery flow — so the wrong registrar can be corrected in
 * the same step the registration is re-submitted.
 */
final class DomainControllerRegisterTest extends DatabaseTestCase
{
    private DomainController $controller;
    private DomainRepository $domains;
    private RegistrarRepository $registrars;
    private FakeRegistrarModule $fake;
    private int $clientId;
    private string $emptyConfigDir;

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        App::container()->instance(\CodeVault\Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $_SESSION = [];
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-domain-register-test-' . uniqid();
        mkdir($this->emptyConfigDir);
        $session = new SessionManager(new Config($this->emptyConfigDir));

        $roles = new RoleRepository($this->db);
        $roleId = $roles->create('Owner', true, []);
        $adminId = (new AdminRepository($this->db))->create('ops', 'ops@example.test', 'secret123', 'Ops Admin', $roleId);
        $_SESSION['admin_id'] = $adminId;

        $this->domains = new DomainRepository($this->db);
        $this->registrars = new RegistrarRepository($this->db);
        $this->registrars->setEnabled('upperlink', true);

        // The real UpperlinkRegistrarModule would hit the network — swap in a
        // scripted double for the same slug so register() exercises the
        // controller/service persistence logic instead.
        $this->fake = new FakeRegistrarModule();
        App::container()->make(ModuleManager::class)->register(RegistrarModule::class, 'upperlink', $this->fake);

        $this->clientId = (new ClientRepository($this->db))->create([
            'email' => 'domain-register-client@example.test',
            'password' => 'secret123',
            'first_name' => 'Domain',
            'last_name' => 'Client',
        ]);

        $this->controller = App::container()->make(DomainController::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->emptyConfigDir);
        parent::tearDown();
    }

    private function createPendingDomain(string $name = 'stuck.com.ng', string $registrarSlug = 'upperlink'): int
    {
        $id = $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => $name,
            'registrar_slug' => $registrarSlug,
            'status' => 'pending',
            'next_due_date' => '2027-08-04',
            'amount' => 7450.00,
        ]);

        $this->db->update(
            'UPDATE domains SET provisioning_error = ? WHERE id = ?',
            ['Domain could not be registered at the registrar (timeout).', $id]
        );

        return $id;
    }

    private function post(int $domainId, array $inputs = []): \CodeVault\Response
    {
        return $this->controller->registerDomain(
            new Request([], $inputs, ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $domainId]
        );
    }

    public function test_register_domain_submits_to_the_registrar_and_activates_the_domain(): void
    {
        $this->fake->respond('register', ['success' => true, 'message' => 'registered', 'registrarDomainId' => 'UP-123', 'expiryDate' => '2028-08-04']);
        $domainId = $this->createPendingDomain();

        $response = $this->post($domainId, ['years' => '2']);

        $this->assertSame(302, $response->status());
        $this->assertSame("/admin/domains/{$domainId}?registered=1", $response->headers()['Location']);

        $domain = $this->domains->find($domainId);
        $this->assertSame('active', $domain['status']);
        $this->assertSame('UP-123', $domain['registrar_domain_id']);
        $this->assertNull($domain['provisioning_error']);

        $registerParams = $this->fake->lastCall('register');
        $this->assertSame('stuck.com.ng', $registerParams['domain']);
        $this->assertSame(2, $registerParams['years']);
    }

    public function test_register_domain_repoints_to_a_new_registrar_then_registers(): void
    {
        $this->fake->respond('register', ['success' => true, 'message' => 'registered', 'registrarDomainId' => 'UP-456', 'expiryDate' => '2027-08-04']);
        // Stuck on the disabled `local` module from the old checkout flow.
        $domainId = $this->createPendingDomain('mispointed.com.ng', 'local');
        $this->db->update(
            'UPDATE domains SET registrar_domain_id = ?, registrar_contact_id = ? WHERE id = ?',
            ['local-handle', 'local-contact', $domainId]
        );

        $response = $this->post($domainId, ['registrar_slug' => 'upperlink', 'years' => '1']);

        $this->assertSame(302, $response->status());
        $this->assertSame("/admin/domains/{$domainId}?registered=1", $response->headers()['Location']);

        $domain = $this->domains->find($domainId);
        $this->assertSame('upperlink', $domain['registrar_slug']);
        // The stale handle from `local` is gone — the new registrar's handle
        // replaces it once the re-submitted registration succeeds.
        $this->assertSame('UP-456', $domain['registrar_domain_id']);
        $this->assertNull($domain['registrar_contact_id']);

        $this->assertSame('mispointed.com.ng', $this->fake->lastCall('register')['domain']);
    }

    public function test_register_domain_rejects_an_unknown_registrar_without_submitting(): void
    {
        $domainId = $this->createPendingDomain();

        $response = $this->post($domainId, ['registrar_slug' => 'ghost-registrar']);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('register_error=', $response->headers()['Location']);
        $this->assertSame([], $this->fake->calls);
        $this->assertSame('pending', $this->domains->find($domainId)['status']);
    }

    public function test_register_domain_failure_redirects_with_error_and_keeps_the_domain_pending(): void
    {
        $this->fake->respond('register', ['success' => false, 'message' => 'Domain name already exists at the registry.']);
        $domainId = $this->createPendingDomain();

        $response = $this->post($domainId);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('register_error=', $response->headers()['Location']);

        $domain = $this->domains->find($domainId);
        $this->assertSame('pending', $domain['status']);
        $this->assertSame('Domain name already exists at the registry.', $domain['provisioning_error']);
    }

    public function test_register_domain_returns_404_for_a_missing_domain(): void
    {
        $response = $this->post(999999);

        $this->assertSame(404, $response->status());
    }

    public function test_register_domain_requires_the_domains_manage_permission(): void
    {
        $roleId = (new RoleRepository($this->db))->create('Support Agent', false, [PermissionRegistry::CLIENTS_VIEW]);
        $adminId = (new AdminRepository($this->db))->create('support', 'support@example.test', 'secret123', 'Support', $roleId);
        $_SESSION['admin_id'] = $adminId;

        $domainId = $this->createPendingDomain();
        $response = $this->post($domainId);

        $this->assertSame(403, $response->status());
        $this->assertSame([], $this->fake->calls);
        $this->assertSame('pending', $this->domains->find($domainId)['status']);
    }

    public function test_register_domain_redirects_to_login_when_not_authenticated(): void
    {
        unset($_SESSION['admin_id']);

        $domainId = $this->createPendingDomain();
        $response = $this->post($domainId);

        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->headers()['Location']);
        $this->assertSame('pending', $this->domains->find($domainId)['status']);
    }
}
