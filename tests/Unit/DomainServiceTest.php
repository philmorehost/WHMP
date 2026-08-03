<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainRepository;
use CodeVault\Domains\DomainService;
use CodeVault\Domains\LocalRegistrarModule;
use CodeVault\Domains\RegistrarRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\RegistrarModule;
use CodeVault\Tests\Fixtures\FakeRegistrarModule;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class DomainServiceTest extends DatabaseTestCase
{
    private DomainRepository $domains;
    private RegistrarRepository $registrars;
    private DomainService $service;
    private string $localStorageDir;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->domains = new DomainRepository($this->db);
        $this->registrars = new RegistrarRepository($this->db);

        $this->localStorageDir = sys_get_temp_dir() . '/codevault-domain-orchestration-' . uniqid();
        $localModule = new LocalRegistrarModule($this->localStorageDir);

        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $modules->register(RegistrarModule::class, 'local', $localModule);

        $clients = new ClientRepository($this->db);
        $this->service = new DomainService($this->domains, $this->registrars, $modules, $hooks, $clients, $this->db, new ActivityLogger($this->db));

        $this->clientId = $clients->create([
            'email' => 'domainowner@example.test',
            'password' => 'secret123',
            'first_name' => 'Domain',
            'last_name' => 'Owner',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->localStorageDir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->localStorageDir);
        parent::tearDown();
    }

    private function createPendingDomain(string $name = 'orchestrated.test'): int
    {
        return $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => $name,
            'registrar_slug' => 'local',
            'status' => 'pending',
            'next_due_date' => (new DateTimeImmutable('+1 year'))->format('Y-m-d'),
            'amount' => 12.99,
        ]);
    }

    public function test_register_activates_the_domain_on_success(): void
    {
        $domainId = $this->createPendingDomain();

        $result = $this->service->register($domainId, 1);

        $this->assertTrue($result['success']);
        $domain = $this->domains->find($domainId);
        $this->assertSame('active', $domain['status']);
        $this->assertNotNull($domain['expiry_date']);
        $this->assertNull($domain['provisioning_error']);
    }

    public function test_register_records_an_error_for_an_unknown_registrar(): void
    {
        $domainId = $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => 'unknownregistrar.test',
            'registrar_slug' => 'does-not-exist',
            'status' => 'pending',
            'next_due_date' => (new DateTimeImmutable('+1 year'))->format('Y-m-d'),
            'amount' => 10.00,
        ]);

        $result = $this->service->register($domainId);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown registrar', $result['message']);
        $this->assertSame('pending', $this->domains->find($domainId)['status']);
    }

    public function test_renew_advances_expiry_and_next_due_date(): void
    {
        $domainId = $this->createPendingDomain('renewme.test');
        $this->service->register($domainId, 1);
        $beforeExpiry = $this->domains->find($domainId)['expiry_date'];

        $result = $this->service->renew($domainId, 1);

        $this->assertTrue($result['success']);
        $domain = $this->domains->find($domainId);
        $this->assertGreaterThan($beforeExpiry, $domain['expiry_date']);
        $this->assertSame($domain['expiry_date'], $domain['next_due_date']);
    }

    /**
     * The grace/redemption check reads domain_pricing directly. It crashed in
     * production — "Call to a member function selectOne() on null" — because
     * DomainService had no Database dependency, taking down the whole
     * mark-invoice-paid request via the DOMAIN_RENEWED hook.
     */
    public function test_renew_consults_tld_pricing_for_a_domain_with_a_matching_pricing_row(): void
    {
        $domainId = $this->createPendingDomain('renewpriced.com');
        $this->service->register($domainId, 1);

        $result = $this->service->renew($domainId, 1);

        $this->assertTrue($result['success'], $result['message'] ?? '');
    }

    public function test_renew_is_refused_once_grace_and_redemption_periods_have_both_elapsed(): void
    {
        $domainId = $this->createPendingDomain('longexpired.com');
        $this->service->register($domainId, 1);

        // .com seeds grace 30 + redemption 30; put expiry well past both.
        $this->db->update(
            'UPDATE domains SET expiry_date = ? WHERE id = ?',
            [(new DateTimeImmutable('-200 days'))->format('Y-m-d'), $domainId]
        );

        $result = $this->service->renew($domainId, 1);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Redemption Period', $result['message']);
    }

    public function test_renew_is_still_allowed_inside_the_grace_period(): void
    {
        $domainId = $this->createPendingDomain('justexpired.com');
        $this->service->register($domainId, 1);

        $this->db->update(
            'UPDATE domains SET expiry_date = ? WHERE id = ?',
            [(new DateTimeImmutable('-10 days'))->format('Y-m-d'), $domainId]
        );

        $result = $this->service->renew($domainId, 1);

        $this->assertTrue($result['success'], $result['message'] ?? '');
    }

    public function test_set_lock_updates_local_state_only_on_module_success(): void
    {
        $domainId = $this->createPendingDomain('lockme.test');
        $this->service->register($domainId, 1);

        $result = $this->service->setLock($domainId, false);

        $this->assertTrue($result['success']);
        $this->assertSame(0, (int) $this->domains->find($domainId)['registrar_lock_enabled']);
    }

    public function test_set_id_protection_updates_local_state(): void
    {
        $domainId = $this->createPendingDomain('protectme.test');
        $this->service->register($domainId, 1);

        $this->service->setIdProtection($domainId, true);

        $this->assertSame(1, (int) $this->domains->find($domainId)['id_protection_enabled']);
    }

    public function test_register_caches_nameservers_from_the_registrar(): void
    {
        $domainId = $this->createPendingDomain('nscache.test');

        $this->service->register($domainId, 1);

        $cached = json_decode($this->domains->find($domainId)['nameservers'], true);
        $this->assertNotEmpty($cached);
    }

    public function test_get_nameservers_refreshes_the_cached_copy(): void
    {
        $domainId = $this->createPendingDomain('nsrefresh.test');
        $this->service->register($domainId, 1);
        $this->service->saveNameservers($domainId, ['ns1.stale.test', 'ns2.stale.test']);

        $result = $this->service->getNameservers($domainId);

        $this->assertTrue($result['success']);
        $cached = json_decode($this->domains->find($domainId)['nameservers'], true);
        $this->assertSame($result['nameservers'], $cached);
    }

    public function test_save_nameservers_updates_the_cached_copy(): void
    {
        $domainId = $this->createPendingDomain('nsupdate.test');
        $this->service->register($domainId, 1);

        $result = $this->service->saveNameservers($domainId, ['ns1.custom.test', 'ns2.custom.test']);

        $this->assertTrue($result['success']);
        $cached = json_decode($this->domains->find($domainId)['nameservers'], true);
        $this->assertSame(['ns1.custom.test', 'ns2.custom.test'], $cached);
    }

    public function test_sync_reconciles_status_and_expiry(): void
    {
        $domainId = $this->createPendingDomain('syncme.test');
        $this->service->register($domainId, 1);

        $result = $this->service->sync($domainId);

        $this->assertTrue($result['success']);
        $this->assertSame('active', $result['status']);
    }

    public function test_actions_on_a_domain_with_no_registrar_module_fail_gracefully(): void
    {
        $domainId = $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => 'nomodule.test',
            'registrar_slug' => 'ghost-registrar',
            'status' => 'active',
            'next_due_date' => (new DateTimeImmutable('+1 year'))->format('Y-m-d'),
            'amount' => 10.00,
        ]);

        $this->assertFalse($this->service->setLock($domainId, true)['success']);
        $this->assertFalse($this->service->getEppCode($domainId)['success']);
    }

    public function test_register_fires_domain_registered_hook(): void
    {
        $fired = [];
        $hooks = new HookDispatcher();
        $hooks->register(HookPoints::DOMAIN_REGISTERED, function (array $p) use (&$fired) {
            $fired[] = $p;
        });
        $modules = new ModuleManager($hooks);
        $modules->register(RegistrarModule::class, 'local', new LocalRegistrarModule($this->localStorageDir));
        $service = new DomainService($this->domains, $this->registrars, $modules, $hooks, new ClientRepository($this->db), $this->db, new ActivityLogger($this->db));

        $domainId = $this->createPendingDomain('hooktest.test');
        $service->register($domainId, 1);

        $this->assertCount(1, $fired);
    }

    /** @return array{0: DomainService, 1: FakeRegistrarModule} */
    private function withFakeRegistrar(): array
    {
        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $fake = new FakeRegistrarModule();
        $modules->register(RegistrarModule::class, 'fake', $fake);
        $clients = new ClientRepository($this->db);
        $service = new DomainService($this->domains, $this->registrars, $modules, $hooks, $clients, $this->db, new ActivityLogger($this->db));

        return [$service, $fake];
    }

    private function createPendingDomainForFakeRegistrar(string $name): int
    {
        return $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => $name,
            'registrar_slug' => 'fake',
            'status' => 'pending',
            'next_due_date' => (new DateTimeImmutable('+1 year'))->format('Y-m-d'),
            'amount' => 12.99,
        ]);
    }

    public function test_register_passes_the_client_profile_and_persists_a_newly_created_registrar_client_id(): void
    {
        [$service, $fake] = $this->withFakeRegistrar();
        $fake->respond('register', ['success' => true, 'message' => 'ok', 'registrarClientId' => '777']);
        $domainId = $this->createPendingDomainForFakeRegistrar('fakeclient.test');

        $service->register($domainId, 1);

        $registerParams = $fake->lastCall('register');
        $this->assertSame('domainowner@example.test', $registerParams['client']['email']);
        $this->assertNull($registerParams['registrarClientId']);

        $client = (new ClientRepository($this->db))->find($this->clientId);
        $this->assertSame('777', $client['registrar_client_id']);
    }

    public function test_renew_passes_the_clients_already_known_registrar_client_id(): void
    {
        [$service, $fake] = $this->withFakeRegistrar();
        $fake->respond('register', ['success' => true, 'message' => 'ok', 'registrarClientId' => '777']);
        $domainId = $this->createPendingDomainForFakeRegistrar('fakerenew.test');
        $service->register($domainId, 1);

        $fake->respond('renew', ['success' => true, 'message' => 'ok', 'expiryDate' => '2030-01-01']);
        $service->renew($domainId, 1);

        $renewParams = $fake->lastCall('renew');
        $this->assertSame('777', $renewParams['registrarClientId']);
    }

    public function test_get_contact_info_passes_the_stored_registrar_contact_id(): void
    {
        [$service, $fake] = $this->withFakeRegistrar();
        $domainId = $this->createPendingDomainForFakeRegistrar('fakecontact.test');
        $this->domains->updateContactId($domainId, '321');
        $fake->respond('getContactInfo', ['success' => true, 'contacts' => ['Name' => 'Jane Doe']]);

        $result = $service->getContactInfo($domainId);

        $this->assertTrue($result['success']);
        $this->assertSame('Jane Doe', $result['contacts']['Name']);
        $this->assertSame('321', $fake->lastCall('getContactInfo')['registrarContactId']);
    }

    public function test_save_contact_info_persists_a_newly_created_contact_id_and_client_id(): void
    {
        [$service, $fake] = $this->withFakeRegistrar();
        $domainId = $this->createPendingDomainForFakeRegistrar('fakesavecontact.test');
        $fake->respond('saveContactInfo', [
            'success' => true,
            'message' => 'Contact info created.',
            'registrarClientId' => '777',
            'registrarContactId' => '321',
        ]);

        $result = $service->saveContactInfo($domainId, ['name' => 'Jane Doe', 'email' => 'jane@example.test']);

        $this->assertTrue($result['success']);

        $saveParams = $fake->lastCall('saveContactInfo');
        $this->assertSame('Jane Doe', $saveParams['contacts']['name']);

        $domain = $this->domains->find($domainId);
        $this->assertSame('321', $domain['registrar_contact_id']);

        $client = (new ClientRepository($this->db))->find($this->clientId);
        $this->assertSame('777', $client['registrar_client_id']);
    }
}
