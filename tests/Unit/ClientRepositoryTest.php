<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

final class ClientRepositoryTest extends DatabaseTestCase
{
    private ClientRepository $clients;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->clients = new ClientRepository($this->db);
    }

    private function sample(array $overrides = []): array
    {
        return array_merge([
            'email' => 'jane@example.test',
            'password' => 'secret123',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'company_name' => 'Acme Inc',
        ], $overrides);
    }

    public function test_create_and_find(): void
    {
        $id = $this->clients->create($this->sample());
        $client = $this->clients->find($id);

        $this->assertNotNull($client);
        $this->assertSame('jane@example.test', $client['email']);
        $this->assertSame('active', $client['status']);
        $this->assertTrue(password_verify('secret123', $client['password_hash']));
    }

    public function test_find_by_email(): void
    {
        $this->clients->create($this->sample());

        $this->assertNotNull($this->clients->findByEmail('jane@example.test'));
        $this->assertNull($this->clients->findByEmail('nobody@example.test'));
    }

    public function test_paginate_search_matches_name_email_and_company(): void
    {
        $this->clients->create($this->sample(['email' => 'jane@example.test', 'first_name' => 'Jane']));
        $this->clients->create($this->sample(['email' => 'bob@example.test', 'first_name' => 'Bob', 'last_name' => 'Smith', 'company_name' => 'Widgets Ltd']));

        $byName = $this->clients->paginate('Jane');
        $this->assertSame(1, $byName['total']);

        $byCompany = $this->clients->paginate('Widgets');
        $this->assertSame(1, $byCompany['total']);
        $this->assertSame('bob@example.test', $byCompany['data'][0]['email']);

        $all = $this->clients->paginate('');
        $this->assertSame(2, $all['total']);
    }

    public function test_paginate_respects_page_size(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->clients->create($this->sample(['email' => "client{$i}@example.test"]));
        }

        $page1 = $this->clients->paginate('', 1, 2);
        $this->assertCount(2, $page1['data']);
        $this->assertSame(5, $page1['total']);

        $page3 = $this->clients->paginate('', 3, 2);
        $this->assertCount(1, $page3['data']);
    }

    public function test_all_for_export_includes_email_and_phone_and_respects_search(): void
    {
        $this->clients->create($this->sample(['email' => 'jane@example.test', 'phone' => '+1-555-0100']));
        $this->clients->create($this->sample(['email' => 'bob@example.test', 'first_name' => 'Bob', 'last_name' => 'Smith', 'phone' => '+1-555-0200']));

        $all = $this->clients->allForExport();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('phone', $all[0]);
        $this->assertArrayHasKey('email', $all[0]);

        $filtered = $this->clients->allForExport('Bob');
        $this->assertCount(1, $filtered);
        $this->assertSame('bob@example.test', $filtered[0]['email']);
        $this->assertSame('+1-555-0200', $filtered[0]['phone']);
    }

    public function test_update_changes_fields(): void
    {
        $id = $this->clients->create($this->sample());

        $this->clients->update($id, $this->sample(['email' => 'jane@example.test', 'first_name' => 'Janet']));

        $this->assertSame('Janet', $this->clients->find($id)['first_name']);
    }

    public function test_close_sets_status_to_closed(): void
    {
        $id = $this->clients->create($this->sample());

        $this->clients->close($id);

        $this->assertSame('closed', $this->clients->find($id)['status']);
    }

    public function test_count_all(): void
    {
        $this->assertSame(0, $this->clients->countAll());

        $this->clients->create($this->sample(['email' => 'a@example.test']));
        $this->clients->create($this->sample(['email' => 'b@example.test']));

        $this->assertSame(2, $this->clients->countAll());
    }

    public function test_count_new_this_month_ignores_older_rows(): void
    {
        $this->clients->create($this->sample(['email' => 'new@example.test']));
        $oldId = $this->clients->create($this->sample(['email' => 'old@example.test']));

        $this->db->update('UPDATE clients SET created_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $oldId]);

        $this->assertSame(1, $this->clients->countNewThisMonth());
    }

    // --- R22: VAT number -----------------------------------------------------

    public function test_create_and_update_persist_vat_number(): void
    {
        $id = $this->clients->create($this->sample(['vat_number' => 'DE123456789']));

        $this->assertSame('DE123456789', $this->clients->find($id)['vat_number']);

        $this->clients->update($id, array_merge($this->sample(), ['vat_number' => 'FR12345678901']));

        $this->assertSame('FR12345678901', $this->clients->find($id)['vat_number']);
    }

    public function test_vat_number_defaults_to_null_when_omitted(): void
    {
        $id = $this->clients->create($this->sample());

        $this->assertNull($this->clients->find($id)['vat_number']);
    }

    public function test_record_vat_verification_persists_valid_result(): void
    {
        $id = $this->clients->create($this->sample(['vat_number' => 'IE6388047V']));

        $this->clients->recordVatVerification($id, true, 'GOOGLE IRELAND LIMITED');
        $client = $this->clients->find($id);

        $this->assertNotNull($client['vat_verified_at']);
        $this->assertSame(1, (int) $client['vat_verified_valid']);
        $this->assertSame('GOOGLE IRELAND LIMITED', $client['vat_verified_name']);
    }

    public function test_record_vat_verification_persists_invalid_result(): void
    {
        $id = $this->clients->create($this->sample(['vat_number' => 'IE00000000']));

        $this->clients->recordVatVerification($id, false, null);
        $client = $this->clients->find($id);

        $this->assertNotNull($client['vat_verified_at']);
        $this->assertSame(0, (int) $client['vat_verified_valid']);
        $this->assertNull($client['vat_verified_name']);
    }
}
