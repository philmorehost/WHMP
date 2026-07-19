<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Hooks\HookDispatcher;
use PHPUnit\Framework\TestCase;

final class HookDispatcherTest extends TestCase
{
    public function test_fire_returns_empty_array_when_no_listeners_registered(): void
    {
        $hooks = new HookDispatcher();

        $this->assertSame([], $hooks->fire('SomeHook'));
        $this->assertFalse($hooks->has('SomeHook'));
    }

    public function test_fire_calls_every_listener_with_the_given_params(): void
    {
        $hooks = new HookDispatcher();
        $received = null;

        $hooks->register('ClientAdd', function (array $params) use (&$received) {
            $received = $params;

            return 'ok';
        });

        $results = $hooks->fire('ClientAdd', ['clientId' => 7]);

        $this->assertTrue($hooks->has('ClientAdd'));
        $this->assertSame(['clientId' => 7], $received);
        $this->assertSame(['ok'], $results);
    }

    public function test_listeners_fire_in_priority_order(): void
    {
        $hooks = new HookDispatcher();
        $order = [];

        $hooks->register('InvoicePaid', function () use (&$order) {
            $order[] = 'second';
        }, 20);

        $hooks->register('InvoicePaid', function () use (&$order) {
            $order[] = 'first';
        }, 5);

        $hooks->fire('InvoicePaid');

        $this->assertSame(['first', 'second'], $order);
    }

    public function test_clear_removes_all_listeners_for_a_hook_point(): void
    {
        $hooks = new HookDispatcher();
        $hooks->register('TicketOpen', fn () => null);

        $hooks->clear('TicketOpen');

        $this->assertFalse($hooks->has('TicketOpen'));
        $this->assertSame([], $hooks->fire('TicketOpen'));
    }

    public function test_registered_reports_listener_counts_per_hook(): void
    {
        $hooks = new HookDispatcher();
        $hooks->register('TicketOpen', fn () => null);
        $hooks->register('TicketOpen', fn () => null);
        $hooks->register('InvoicePaid', fn () => null);

        $this->assertSame([
            'TicketOpen' => 2,
            'InvoicePaid' => 1,
        ], $hooks->registered());
    }
}
