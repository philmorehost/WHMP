<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ClientRenewalController;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\RecurringBillingService;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainRenewalBillingService;
use CodeVault\Domains\DomainRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * Client-initiated renewal ("Renew Now"). The behaviour these tests pin is
 * the whole contract of the feature:
 *
 *  - clicking Renew raises this cycle's renewal invoice and redirects the
 *    client to it to pay (nothing renews yet — payment does that);
 *  - a client renewing another client's service or domain gets a 404 and no
 *    invoice is raised;
 *  - a closed service is refused with a message on its own page, again
 *    without raising an invoice.
 */
final class ClientRenewalControllerTest extends DatabaseTestCase
{
    private ServiceRepository $services;
    private DomainRepository $domains;
    private ClientRepository $clients;
    private ClientRenewalController $controller;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->services = new ServiceRepository($this->db);
        $this->domains = new DomainRepository($this->db);
        $this->clients = new ClientRepository($this->db);

        $tax = new TaxCalculator(
            new TaxRuleRepository($this->db),
            new VatNumberValidator(),
            new TaxSettings(new SettingsRepository($this->db))
        );
        $currency = new CurrencyService(new CurrencyRepository($this->db));
        $hooks = new HookDispatcher();

        $this->clientId = $this->clients->create([
            'email' => 'renewclick@example.test',
            'password' => 'secret123',
            'first_name' => 'Renew',
            'last_name' => 'Clicker',
        ]);

        $this->controller = new ClientRenewalController(
            $this->guardForClient($this->clientId),
            $this->services,
            $this->domains,
            new RecurringBillingService($this->services, $this->clients, $tax, $currency, $this->db, $hooks),
            new DomainRenewalBillingService($this->domains, $this->clients, $tax, $this->db, $hooks, $currency)
        );
    }

    private function guardForClient(int $clientId): ClientAuthGuard
    {
        $session = $this->createMock(SessionManager::class);
        $session->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null) => $key === 'client_id' ? $clientId : $default
        );

        return new ClientAuthGuard($session, $this->clients);
    }

    private function request(): Request
    {
        return new Request([], [], [
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '1.2.3.4',
            'REQUEST_URI' => '/',
        ], []);
    }

    private function createService(int $clientId, string $dueDate): int
    {
        $groupId = (new ProductGroupRepository($this->db))->create('Hosting', null);
        $productId = (new ProductRepository($this->db))->create(['product_group_id' => $groupId, 'name' => 'Starter']);

        return $this->services->create([
            'client_id' => $clientId,
            'product_id' => $productId,
            'product_name' => 'Starter',
            'billing_cycle' => 'monthly',
            'amount' => 9.99,
            'status' => 'active',
            'next_due_date' => $dueDate,
        ]);
    }

    private function location(\CodeVault\Response $response): string
    {
        return (string) ($response->headers()['Location'] ?? '');
    }

    public function test_renewing_a_service_redirects_to_a_fresh_renewal_invoice(): void
    {
        $serviceId = $this->createService($this->clientId, (new DateTimeImmutable('+120 days'))->format('Y-m-d'));

        $response = $this->controller->service($this->request(), ['id' => (string) $serviceId]);

        $this->assertSame(302, $response->status());

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE service_id = ?', [$serviceId]);
        $this->assertNotNull($invoice);
        $this->assertSame('unpaid', $invoice['status']);
        // The flag is what renders the "pay online and it renews instantly /
        // pay by transfer and contact us" notice on the invoice page.
        $this->assertSame('/client/invoices/' . $invoice['id'] . '?payment=renewal', $this->location($response));
    }

    public function test_renewing_a_service_suspended_for_non_payment_still_works(): void
    {
        $serviceId = $this->createService($this->clientId, (new DateTimeImmutable('+120 days'))->format('Y-m-d'));
        $this->services->suspend($serviceId);

        $response = $this->controller->service($this->request(), ['id' => (string) $serviceId]);

        $this->assertSame(302, $response->status());
        $this->assertNotNull($this->db->selectOne('SELECT id FROM invoices WHERE service_id = ?', [$serviceId]));
    }

    public function test_renewing_another_clients_service_is_a_404_and_creates_nothing(): void
    {
        $otherClientId = $this->clients->create([
            'email' => 'otherrenew@example.test',
            'password' => 'secret123',
            'first_name' => 'Other',
            'last_name' => 'Client',
        ]);
        $serviceId = $this->createService($otherClientId, (new DateTimeImmutable('+120 days'))->format('Y-m-d'));

        $response = $this->controller->service($this->request(), ['id' => (string) $serviceId]);

        $this->assertSame(404, $response->status());
        $this->assertCount(0, $this->db->select('SELECT id FROM invoices WHERE service_id = ?', [$serviceId]));
    }

    public function test_a_closed_service_redirects_back_with_an_error_and_no_invoice(): void
    {
        $serviceId = $this->createService($this->clientId, (new DateTimeImmutable('+120 days'))->format('Y-m-d'));
        $this->services->updateStatus($serviceId, 'cancelled');

        $response = $this->controller->service($this->request(), ['id' => (string) $serviceId]);

        $this->assertSame(302, $response->status());
        $this->assertStringStartsWith('/client/services/' . $serviceId . '?err=', $this->location($response));
        $this->assertCount(0, $this->db->select('SELECT id FROM invoices WHERE service_id = ?', [$serviceId]));
    }

    public function test_renewing_a_domain_redirects_to_a_fresh_renewal_invoice(): void
    {
        $domainId = $this->domains->create([
            'client_id' => $this->clientId,
            'domain_name' => 'renewclick' . uniqid() . '.test',
            'registrar_slug' => 'local',
            'status' => 'active',
            'next_due_date' => (new DateTimeImmutable('+200 days'))->format('Y-m-d'),
            'expiry_date' => (new DateTimeImmutable('+200 days'))->format('Y-m-d'),
            // Manual renewal must work with auto-renew off.
            'auto_renew' => 0,
            'amount' => 14.99,
        ]);

        $response = $this->controller->domain($this->request(), ['id' => (string) $domainId]);

        $this->assertSame(302, $response->status());

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE domain_id = ?', [$domainId]);
        $this->assertNotNull($invoice);
        $this->assertSame('unpaid', $invoice['status']);
        $this->assertSame('/client/invoices/' . $invoice['id'] . '?payment=renewal', $this->location($response));
    }

    public function test_renewing_another_clients_domain_is_a_404_and_creates_nothing(): void
    {
        $otherClientId = $this->clients->create([
            'email' => 'otherrenewdomain@example.test',
            'password' => 'secret123',
            'first_name' => 'Other',
            'last_name' => 'Client',
        ]);
        $domainId = $this->domains->create([
            'client_id' => $otherClientId,
            'domain_name' => 'otherrenew' . uniqid() . '.test',
            'registrar_slug' => 'local',
            'status' => 'active',
            'next_due_date' => (new DateTimeImmutable('+200 days'))->format('Y-m-d'),
            'expiry_date' => (new DateTimeImmutable('+200 days'))->format('Y-m-d'),
            'auto_renew' => 0,
            'amount' => 14.99,
        ]);

        $response = $this->controller->domain($this->request(), ['id' => (string) $domainId]);

        $this->assertSame(404, $response->status());
        $this->assertCount(0, $this->db->select('SELECT id FROM invoices WHERE domain_id = ?', [$domainId]));
    }
}
