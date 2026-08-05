<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * Standalone domain registrations ride on the hidden "Domain Registration"
 * carrier product (seeded by migration 0103, DomainService::carrierProductId()),
 * which makes them look like ordinary services in the services table. The
 * client-facing services page must not list them — domains have their own
 * manager at /client/domains. These tests lock in the data-layer assumptions
 * the controller's filter relies on: the carrier product lookup finds the
 * seeded row, and the filter predicate removes only carrier services.
 */
final class ClientServiceDomainFilterTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private ServiceRepository $services;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->services = new ServiceRepository($this->db);

        $this->clientId = $this->clients->create([
            'email' => 'filter-' . uniqid() . '@example.test',
            'password' => 'whatever123',
            'first_name' => 'Filter',
            'last_name' => 'Test',
        ]);
    }

    public function test_carrier_product_lookup_finds_the_seeded_domain_registration_product(): void
    {
        $carrierId = $this->carrierProductId();

        $this->assertGreaterThan(0, $carrierId);

        $row = $this->db->selectOne(
            'SELECT name, status FROM products WHERE id = ?',
            [$carrierId]
        );

        $this->assertNotNull($row);
        $this->assertSame('Domain Registration', $row['name']);
        $this->assertSame('hidden', $row['status']);
    }

    public function test_filter_keeps_only_real_services_not_domain_carriers(): void
    {
        $carrierId = $this->carrierProductId();
        $normalProductId = $this->insertProduct('Shared Hosting');

        $carrierServiceId = $this->insertService($carrierId, 'Domain Registration', 'example' . uniqid() . '.com');
        $normalServiceId = $this->insertService($normalProductId, 'Shared Hosting', 'client.example.com');

        $services = $this->services->forClient($this->clientId);

        $filtered = array_values(array_filter(
            $services,
            static fn (array $svc): bool => (int) $svc['product_id'] !== $carrierId
        ));

        $ids = array_column($filtered, 'id');

        $this->assertContains($normalServiceId, $ids);
        $this->assertNotContains($carrierServiceId, $ids);
    }

    private function carrierProductId(): int
    {
        return (int) ($this->db->selectOne(
            "SELECT id FROM products WHERE name = 'Domain Registration' AND status = 'hidden' LIMIT 1"
        )['id'] ?? 0);
    }

    private function insertProduct(string $name, string $status = 'active'): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $groupId = (int) $this->db->insert(
            'INSERT INTO product_groups (name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['Test Group', 0, $now, $now]
        );

        return (int) $this->db->insert(
            'INSERT INTO products (product_group_id, name, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$groupId, $name, $status, 0, $now, $now]
        );
    }

    private function insertService(int $productId, string $productName, string $domain): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $due = (new DateTimeImmutable('+1 month'))->format('Y-m-d');

        return (int) $this->db->insert(
            'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, domain, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $productId, $productName, 'monthly', 9.99, 'active', $domain, $due, $now, $now]
        );
    }
}
