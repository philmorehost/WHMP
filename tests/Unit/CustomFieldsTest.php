<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\CustomFields\CustomFieldRepository;
use CodeVault\CustomFields\CustomFieldValueRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

final class CustomFieldsTest extends DatabaseTestCase
{
    private CustomFieldRepository $fields;
    private CustomFieldValueRepository $values;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->fields = new CustomFieldRepository($this->db);
        $this->values = new CustomFieldValueRepository($this->db);

        $clients = new ClientRepository($this->db);
        $this->clientId = $clients->create([
            'email' => 'jane@example.test',
            'password' => 'secret123',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
    }

    public function test_create_and_list_fields_for_type(): void
    {
        $this->fields->create('client', 'VAT Number', 'text', null, true, false);
        $this->fields->create('client', 'Internal Note', 'textarea', null, false, true);

        $clientFields = $this->fields->forType('client');

        $this->assertCount(2, $clientFields);
    }

    public function test_saving_and_retrieving_values_for_a_client(): void
    {
        $fieldId = $this->fields->create('client', 'VAT Number', 'text', null, true, false);

        $this->values->saveForClient($this->clientId, [$fieldId => 'GB123456789']);

        $this->assertSame('GB123456789', $this->values->forClient($this->clientId)[$fieldId]);
    }

    public function test_saving_a_value_twice_updates_rather_than_duplicates(): void
    {
        $fieldId = $this->fields->create('client', 'VAT Number', 'text', null, true, false);

        $this->values->saveForClient($this->clientId, [$fieldId => 'first-value']);
        $this->values->saveForClient($this->clientId, [$fieldId => 'second-value']);

        $stored = $this->values->forClient($this->clientId);

        $this->assertCount(1, $stored);
        $this->assertSame('second-value', $stored[$fieldId]);
    }

    public function test_update_changes_definition(): void
    {
        $fieldId = $this->fields->create('client', 'Old Name', 'text', null, false, false);

        $this->fields->update($fieldId, 'New Name', 'textarea', null, true, true);

        $updated = $this->fields->find($fieldId);
        $this->assertSame('New Name', $updated['name']);
        $this->assertSame('textarea', $updated['type']);
        $this->assertSame(1, (int) $updated['required']);
    }

    public function test_delete_removes_the_field(): void
    {
        $fieldId = $this->fields->create('client', 'Temp', 'text', null, false, false);

        $this->fields->delete($fieldId);

        $this->assertNull($this->fields->find($fieldId));
    }
}
