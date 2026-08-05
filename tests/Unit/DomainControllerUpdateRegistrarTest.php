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
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Staff\RoleRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The domain manage page lets an admin re-point a domain at a different
 * registrar — the recovery path for domains that slipped through on the
 * disabled `local` module (e.g. the old checkout hardcode) or that moved
 * registrars since. Switching must clear the old registrar's opaque domain
 * and contact handles, and must refuse a registrar slug that doesn't exist.
 */
final class DomainControllerUpdateRegistrarTest extends DatabaseTestCase
{
    private DomainController $controller;
    private DomainRepository $domains;
    private RegistrarRepository $registrars;
    private int $clientId;
    private string $emptyConfigDir;

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        \CodeVault\Support\App::container()->instance(\CodeVault\Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $_SESSION = [];
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-domain-registrar-test-' . uniqid();
        mkdir($this->emptyConfigDir);
        $session = new SessionManager(new Config($this->emptyConfigDir));

        $roles = new RoleRepository($this->db);
        $roleId = $roles->create('Owner', true, []);
        $adminId = (new AdminRepository($this->db))->create('ops', 'ops@example.test', 'secret123', 'Ops Admin', $roleId);
        $_SESSION['admin_id'] = $adminId;

        $this->domains = new DomainRepository($this->db);
        $this->registrars = new RegistrarRepository($this->db);
        $this->registrars->setEnabled('upperlink', true);
        $this->registrars->setEnabled('connectreseller', true);

        $this->clientId = (new ClientRepository($this->db))->create([
            'email' => 'domain-registrar-client@example.test',
            'password' => 'secret123',
            'first_name' => 'Domain',
            'last_name' => 'Client',
        ]);

        $this->controller = \CodeVault\Support\App::container()->make(DomainController::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->emptyConfigDir);
        parent::tearDown();
    }

    private function createDomain(string $name = 'bobis.com.ng'): int
    {
        return $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => $name,
            'registrar_slug' => 'local',
            'status' => 'active',
            'next_due_date' => '2027-08-04',
            'amount' => 7450.00,
        ]);
    }

    private function post(string $registrarSlug, int $domainId): \CodeVault\Response
    {
        return $this->controller->updateRegistrar(
            new Request([], ['registrar_slug' => $registrarSlug], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $domainId]
        );
    }

    public function test_update_registrar_changes_the_registrar_and_clears_old_handles(): void
    {
        $domainId = $this->createDomain();
        // A handle owned by the old (local) registrar — must not survive a switch.
        $this->db->update(
            'UPDATE domains SET registrar_domain_id = ?, registrar_contact_id = ? WHERE id = ?',
            ['local-handle', 'local-contact', $domainId]
        );

        $response = $this->post('upperlink', $domainId);

        $this->assertSame(302, $response->status());
        $this->assertSame("/admin/domains/{$domainId}?updated=1", $response->headers()['Location']);

        $domain = $this->domains->find($domainId);
        $this->assertSame('upperlink', $domain['registrar_slug']);
        $this->assertNull($domain['registrar_domain_id']);
        $this->assertNull($domain['registrar_contact_id']);
    }

    public function test_update_registrar_is_a_noop_when_the_same_registrar_is_selected(): void
    {
        $domainId = $this->createDomain();
        $this->db->update('UPDATE domains SET registrar_domain_id = ? WHERE id = ?', ['keep-me', $domainId]);

        $response = $this->post('local', $domainId);

        $this->assertSame(302, $response->status());
        $domain = $this->domains->find($domainId);
        $this->assertSame('local', $domain['registrar_slug']);
        $this->assertSame('keep-me', $domain['registrar_domain_id']);
    }

    public function test_update_registrar_rejects_an_unknown_registrar_without_changing_the_domain(): void
    {
        $domainId = $this->createDomain();

        $response = $this->post('ghost-registrar', $domainId);

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('registrar_error=', $response->headers()['Location']);
        $this->assertSame('local', $this->domains->find($domainId)['registrar_slug']);
    }

    public function test_update_registrar_returns_404_for_a_missing_domain(): void
    {
        $response = $this->post('upperlink', 999999);

        $this->assertSame(404, $response->status());
    }

    public function test_update_registrar_requires_the_domains_manage_permission(): void
    {
        $roleId = (new RoleRepository($this->db))->create('Support Agent', false, [PermissionRegistry::CLIENTS_VIEW]);
        $adminId = (new AdminRepository($this->db))->create('support', 'support@example.test', 'secret123', 'Support', $roleId);
        $_SESSION['admin_id'] = $adminId;

        $domainId = $this->createDomain();
        $response = $this->post('upperlink', $domainId);

        $this->assertSame(403, $response->status());
        $this->assertSame('local', $this->domains->find($domainId)['registrar_slug']);
    }

    public function test_update_registrar_redirects_to_login_when_not_authenticated(): void
    {
        unset($_SESSION['admin_id']);

        $domainId = $this->createDomain();
        $response = $this->post('upperlink', $domainId);

        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->headers()['Location']);
        $this->assertSame('local', $this->domains->find($domainId)['registrar_slug']);
    }
}
