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
 * The domains list lets an admin re-point several domains at a different
 * registrar in one go. Bulk semantics must match the per-domain
 * updateRegistrar(): only configured registrars are accepted, the old
 * registrar's opaque domain/contact handles are cleared, and a fully
 * bogus request changes nothing.
 */
final class DomainControllerBulkRegistrarTest extends DatabaseTestCase
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
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-bulk-registrar-test-' . uniqid();
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
            'email' => 'bulk-registrar-client@example.test',
            'password' => 'secret123',
            'first_name' => 'Bulk',
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

    private function createDomain(string $name, string $registrarSlug = 'local'): int
    {
        return $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => $name,
            'registrar_slug' => $registrarSlug,
            'status' => 'active',
            'next_due_date' => '2027-08-04',
            'amount' => 7450.00,
        ]);
    }

    private function post(array $domainIds, string $registrarSlug): \CodeVault\Response
    {
        return $this->controller->bulkUpdateRegistrar(
            new Request([], ['domain_ids' => $domainIds, 'registrar_slug' => $registrarSlug], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], [])
        );
    }

    public function test_bulk_update_registrar_changes_all_selected_domains_and_clears_old_handles(): void
    {
        $a = $this->createDomain('alpha.com');
        $b = $this->createDomain('beta.com');
        $c = $this->createDomain('gamma.com', 'upperlink');
        // Handles owned by the old registrars — must not survive the switch.
        $this->db->update('UPDATE domains SET registrar_domain_id = ?, registrar_contact_id = ? WHERE id = ?', ['a-handle', 'a-contact', $a]);
        $this->db->update('UPDATE domains SET registrar_domain_id = ?, registrar_contact_id = ? WHERE id = ?', ['b-handle', 'b-contact', $b]);

        $response = $this->post([$a, $b], 'upperlink');

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('bulk_msg=', $response->headers()['Location']);

        $this->assertSame('upperlink', $this->domains->find($a)['registrar_slug']);
        $this->assertNull($this->domains->find($a)['registrar_domain_id']);
        $this->assertNull($this->domains->find($a)['registrar_contact_id']);
        $this->assertSame('upperlink', $this->domains->find($b)['registrar_slug']);

        // A non-selected domain is left untouched.
        $this->assertSame('upperlink', $this->domains->find($c)['registrar_slug']);
    }

    public function test_bulk_update_registrar_rejects_an_unknown_registrar_without_changing_domains(): void
    {
        $a = $this->createDomain('alpha.com');
        $b = $this->createDomain('beta.com');

        $response = $this->post([$a, $b], 'ghost-registrar');

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('bulk_msg=', $response->headers()['Location']);
        $this->assertStringNotContainsString('Successfully', $response->headers()['Location']);
        $this->assertSame('local', $this->domains->find($a)['registrar_slug']);
        $this->assertSame('local', $this->domains->find($b)['registrar_slug']);
    }

    public function test_bulk_update_registrar_redirects_when_no_domains_are_selected(): void
    {
        $response = $this->post([], 'upperlink');

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('No domains were selected', urldecode($response->headers()['Location']));
    }

    public function test_bulk_update_registrar_redirects_when_no_registrar_is_selected(): void
    {
        $a = $this->createDomain('alpha.com');

        $response = $this->post([$a], '');

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('valid registrar', urldecode($response->headers()['Location']));
        $this->assertSame('local', $this->domains->find($a)['registrar_slug']);
    }

    public function test_bulk_update_registrar_requires_the_domains_manage_permission(): void
    {
        $roleId = (new RoleRepository($this->db))->create('Support Agent', false, [PermissionRegistry::CLIENTS_VIEW]);
        $adminId = (new AdminRepository($this->db))->create('support', 'support@example.test', 'secret123', 'Support', $roleId);
        $_SESSION['admin_id'] = $adminId;

        $a = $this->createDomain('alpha.com');
        $response = $this->post([$a], 'upperlink');

        $this->assertSame(403, $response->status());
        $this->assertSame('local', $this->domains->find($a)['registrar_slug']);
    }

    public function test_bulk_update_registrar_redirects_to_login_when_not_authenticated(): void
    {
        unset($_SESSION['admin_id']);

        $a = $this->createDomain('alpha.com');
        $response = $this->post([$a], 'upperlink');

        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->headers()['Location']);
        $this->assertSame('local', $this->domains->find($a)['registrar_slug']);
    }
}
