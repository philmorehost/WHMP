<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\PaymentMethodRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

final class PaymentMethodRepositoryTest extends DatabaseTestCase
{
    private PaymentMethodRepository $methods;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->methods = new PaymentMethodRepository($this->db);
        $this->clientId = (new ClientRepository($this->db))->create([
            'email' => 'cards@example.test',
            'password' => 'secret123',
            'first_name' => 'Card',
            'last_name' => 'Holder',
        ]);
    }

    public function test_first_stored_method_becomes_default(): void
    {
        $id = $this->methods->store($this->clientId, 'paystack', 'AUTH_1', ['brand' => 'visa', 'last4' => '4081']);

        $default = $this->methods->defaultForClient($this->clientId);
        $this->assertNotNull($default);
        $this->assertSame($id, (int) $default['id']);
        $this->assertSame(1, (int) $default['is_default']);
    }

    public function test_storing_the_same_token_again_updates_rather_than_duplicates(): void
    {
        $first = $this->methods->store($this->clientId, 'paystack', 'AUTH_1', ['brand' => 'visa', 'last4' => '4081']);
        $second = $this->methods->store($this->clientId, 'paystack', 'AUTH_1', ['brand' => 'visa', 'last4' => '9999']);

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->methods->forClient($this->clientId));
        $this->assertSame('9999', $this->methods->find($first)['card_last4']);
    }

    public function test_make_default_moves_the_flag(): void
    {
        $a = $this->methods->store($this->clientId, 'paystack', 'AUTH_A', ['last4' => '1111']);
        $b = $this->methods->store($this->clientId, 'paystack', 'AUTH_B', ['last4' => '2222']);

        $this->methods->makeDefault($this->clientId, $b);

        $this->assertSame(0, (int) $this->methods->find($a)['is_default']);
        $this->assertSame(1, (int) $this->methods->find($b)['is_default']);
        $this->assertSame($b, (int) $this->methods->defaultForClient($this->clientId)['id']);
    }

    public function test_deactivating_the_default_promotes_another_method(): void
    {
        $a = $this->methods->store($this->clientId, 'paystack', 'AUTH_A', ['last4' => '1111']); // default
        $b = $this->methods->store($this->clientId, 'paystack', 'AUTH_B', ['last4' => '2222']);

        $this->methods->deactivate($this->clientId, $a);

        $active = $this->methods->forClient($this->clientId);
        $this->assertCount(1, $active);
        $this->assertSame($b, (int) $active[0]['id']);
        $this->assertSame(1, (int) $active[0]['is_default'], 'the remaining method should be promoted to default');
    }

    public function test_a_client_cannot_deactivate_another_clients_method(): void
    {
        $otherClient = (new ClientRepository($this->db))->create([
            'email' => 'other@example.test',
            'password' => 'secret123',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);
        $mine = $this->methods->store($this->clientId, 'paystack', 'AUTH_MINE', ['last4' => '1111']);

        $this->methods->deactivate($otherClient, $mine);

        // Still active — ownership mismatch is a no-op.
        $this->assertCount(1, $this->methods->forClient($this->clientId));
    }
}
