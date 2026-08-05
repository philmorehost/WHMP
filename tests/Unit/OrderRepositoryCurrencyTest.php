<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\OrderRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

/**
 * Orders lock the currency the client was shown at checkout
 * (currency_id + currency_rate, rate 1.0 = "already in this currency").
 * The admin order list/detail must display that locked currency — falling
 * back to the client's current preference, then the system default — and
 * never hardcode the base symbol.
 */
final class OrderRepositoryCurrencyTest extends DatabaseTestCase
{
    private CurrencyRepository $currencies;
    private ClientRepository $clients;
    private OrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->currencies = new CurrencyRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->orders = new OrderRepository($this->db);
    }

    public function test_locked_currency_resolves_from_order_not_client(): void
    {
        $naira = $this->currencies->create('NGN', '₦', 1490.0000);
        // Client is currently on the default (USD)...
        $clientId = $this->createClient(null);
        // ...but the order itself was locked to NGN at checkout.
        $orderId = $this->insertOrder($clientId, $naira);

        $order = $this->orders->find($orderId);

        $this->assertSame('NGN', $order['currency_code']);
        $this->assertSame('₦', $order['currency_symbol']);
    }

    public function test_unlocked_order_falls_back_to_client_currency(): void
    {
        $naira = $this->currencies->create('NGN', '₦', 1490.0000);
        $clientId = $this->createClient($naira);
        $orderId = $this->insertOrder($clientId, null);

        $order = $this->orders->find($orderId);

        $this->assertSame('NGN', $order['currency_code']);
        $this->assertSame('₦', $order['currency_symbol']);
    }

    public function test_unlocked_order_without_client_currency_falls_back_to_default(): void
    {
        $clientId = $this->createClient(null);
        $orderId = $this->insertOrder($clientId, null);

        $order = $this->orders->find($orderId);

        $this->assertSame('USD', $order['currency_code']);
        $this->assertSame('$', $order['currency_symbol']);
    }

    public function test_all_resolves_locked_currency_per_row(): void
    {
        $naira = $this->currencies->create('NGN', '₦', 1490.0000);
        $clientId = $this->createClient(null);

        $ngnOrderId = $this->insertOrder($clientId, $naira);
        $usdOrderId = $this->insertOrder($clientId, null);

        $rows = $this->orders->all();
        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $this->assertSame('NGN', $byId[$ngnOrderId]['currency_code']);
        $this->assertSame('₦', $byId[$ngnOrderId]['currency_symbol']);
        $this->assertSame('USD', $byId[$usdOrderId]['currency_code']);
        $this->assertSame('$', $byId[$usdOrderId]['currency_symbol']);
    }

    private function createClient(?int $currencyId): int
    {
        return $this->clients->create([
            'email' => 'currency-' . uniqid() . '@example.test',
            'password' => 'whatever123',
            'first_name' => 'Cur',
            'last_name' => 'Rency',
            'currency_id' => $currencyId,
        ]);
    }

    private function insertOrder(int $clientId, ?int $currencyId): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'pending', 7501.50, $currencyId, 1.0000, $now, $now]
        );
    }
}
