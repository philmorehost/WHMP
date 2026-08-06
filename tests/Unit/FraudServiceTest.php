<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\OrderRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Fraud\FraudService;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Modules\FraudModule;
use CodeVault\Modules\ModuleManager;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class FraudServiceTest extends DatabaseTestCase
{
    private OrderRepository $orders;
    private ClientRepository $clients;
    private ModuleManager $modules;
    private int $clientId;
    private CurrencyService $currency;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->orders = new OrderRepository($this->db);
        $this->clients = new ClientRepository($this->db);
        $this->modules = new ModuleManager(new HookDispatcher());
        $this->currency = new CurrencyService(new CurrencyRepository($this->db));

        $this->clientId = $this->clients->create([
            'email' => 'fraudtest@example.test',
            'password' => 'secret123',
            'first_name' => 'Fraud',
            'last_name' => 'Test',
        ]);
    }

    private function createOrder(float $total): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$this->clientId, 'pending', $total, $now, $now]
        );
    }

    private function createOrderFor(?int $currencyId, float $total): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'pending', $total, $currencyId, 1.0000, $now, $now]
        );
    }

    private function stubModule(float $score, bool $hold, array $reasons): FraudModule
    {
        return new class ($score, $hold, $reasons) implements FraudModule {
            public function __construct(private float $score, private bool $hold, private array $reasons)
            {
            }

            public function metadata(): array
            {
                return ['name' => 'stub', 'description' => '', 'version' => '1.0.0', 'author' => 'test'];
            }

            public function configOptions(): array
            {
                return [];
            }

            public function score(array $order): array
            {
                return ['score' => $this->score, 'hold' => $this->hold, 'reasons' => $this->reasons];
            }
        };
    }

    public function test_takes_the_highest_score_across_modules_not_the_average(): void
    {
        $this->modules->register(FraudModule::class, 'low', $this->stubModule(20.0, false, ['low reason']));
        $this->modules->register(FraudModule::class, 'high', $this->stubModule(80.0, true, ['high reason']));

        $service = new FraudService($this->modules, $this->orders, $this->clients, new HookDispatcher(), $this->currency);
        $orderId = $this->createOrder(50.0);

        $service->evaluate($orderId);

        $order = $this->orders->find($orderId);
        $this->assertSame('80.00', $order['fraud_score']);
        $this->assertSame('fraud', $order['status']);

        $reasons = json_decode((string) $order['fraud_reasons'], true);
        $this->assertContains('low reason', $reasons);
        $this->assertContains('high reason', $reasons);
    }

    public function test_order_not_held_when_no_module_flags_it(): void
    {
        $this->modules->register(FraudModule::class, 'quiet', $this->stubModule(10.0, false, []));

        $service = new FraudService($this->modules, $this->orders, $this->clients, new HookDispatcher(), $this->currency);
        $orderId = $this->createOrder(50.0);

        $service->evaluate($orderId);

        $order = $this->orders->find($orderId);
        $this->assertSame('pending', $order['status']);
        $this->assertSame('10.00', $order['fraud_score']);
    }

    public function test_fires_order_fraud_flagged_hook_when_held(): void
    {
        $this->modules->register(FraudModule::class, 'high', $this->stubModule(80.0, true, ['suspicious']));
        $hooks = new HookDispatcher();
        $fired = [];
        $hooks->register(\CodeVault\Hooks\HookPoints::ORDER_FRAUD_FLAGGED, function (array $p) use (&$fired) {
            $fired[] = $p;
        });

        $service = new FraudService($this->modules, $this->orders, $this->clients, $hooks, $this->currency);
        $orderId = $this->createOrder(50.0);
        $service->evaluate($orderId);

        $this->assertCount(1, $fired);
        $this->assertSame($orderId, $fired[0]['orderId']);
        $this->assertSame(80.0, $fired[0]['score']);
    }

    public function test_does_not_fire_order_fraud_flagged_hook_when_not_held(): void
    {
        $this->modules->register(FraudModule::class, 'quiet', $this->stubModule(10.0, false, []));
        $hooks = new HookDispatcher();
        $fired = [];
        $hooks->register(\CodeVault\Hooks\HookPoints::ORDER_FRAUD_FLAGGED, function (array $p) use (&$fired) {
            $fired[] = $p;
        });

        $service = new FraudService($this->modules, $this->orders, $this->clients, $hooks, $this->currency);
        $orderId = $this->createOrder(50.0);
        $service->evaluate($orderId);

        $this->assertCount(0, $fired);
    }

    public function test_no_registered_modules_leaves_order_unheld_with_zero_score(): void
    {
        $service = new FraudService($this->modules, $this->orders, $this->clients, new HookDispatcher(), $this->currency);
        $orderId = $this->createOrder(50.0);

        $service->evaluate($orderId);

        $order = $this->orders->find($orderId);
        $this->assertSame('pending', $order['status']);
        $this->assertSame('0.00', $order['fraud_score']);
    }

    public function test_unknown_order_id_is_a_no_op(): void
    {
        $this->modules->register(FraudModule::class, 'high', $this->stubModule(80.0, true, []));
        $service = new FraudService($this->modules, $this->orders, $this->clients, new HookDispatcher(), $this->currency);

        $service->evaluate(999999);

        $this->assertNull($this->orders->find(999999));
    }

    public function test_ngn_denominated_order_total_is_normalized_to_base_for_modules(): void
    {
        $currencies = new CurrencyRepository($this->db);
        $ngnId = $currencies->create('NGN', '₦', 1490.0000);

        $seen = [];
        $this->modules->register(FraudModule::class, 'spy', new class ($seen) implements FraudModule {
            public function __construct(private array &$seen)
            {
            }

            public function metadata(): array
            {
                return ['name' => 'spy', 'description' => '', 'version' => '1.0.0', 'author' => 'test'];
            }

            public function configOptions(): array
            {
                return [];
            }

            public function score(array $order): array
            {
                $this->seen['total'] = $order['total'];

                return ['score' => 0.0, 'hold' => false, 'reasons' => []];
            }
        });

        $service = new FraudService($this->modules, $this->orders, $this->clients, new HookDispatcher(), $this->currency);
        // A ₦19,370 denominated order is a $13 base-currency order.
        $orderId = $this->createOrderFor($ngnId, 19370.0);

        $service->evaluate($orderId);

        $this->assertSame(13.0, $seen['total']);
        $order = $this->orders->find($orderId);
        $this->assertSame('pending', $order['status']);
    }

    public function test_locked_rate_order_total_passes_through_unchanged(): void
    {
        $currencies = new CurrencyRepository($this->db);
        $ngnId = $currencies->create('NGN', '₦', 1490.0000);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $orderId = (int) $this->db->insert(
            'INSERT INTO orders (client_id, status, total, currency_id, currency_rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'pending', 13.0, $ngnId, 1490.0000, $now, $now]
        );

        $seen = [];
        $this->modules->register(FraudModule::class, 'spy', new class ($seen) implements FraudModule {
            public function __construct(private array &$seen)
            {
            }

            public function metadata(): array
            {
                return ['name' => 'spy', 'description' => '', 'version' => '1.0.0', 'author' => 'test'];
            }

            public function configOptions(): array
            {
                return [];
            }

            public function score(array $order): array
            {
                $this->seen['total'] = $order['total'];

                return ['score' => 0.0, 'hold' => false, 'reasons' => []];
            }
        });

        $service = new FraudService($this->modules, $this->orders, $this->clients, new HookDispatcher(), $this->currency);
        $service->evaluate($orderId);

        $this->assertSame(13.0, $seen['total']);
    }
}
