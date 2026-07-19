<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;

final class ArrayCacheTest extends TestCase
{
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        $this->assertSame('fallback', $this->cache->get('missing', 'fallback'));
    }

    public function test_set_then_get_returns_the_stored_value(): void
    {
        $this->cache->set('key', ['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $this->cache->get('key'));
    }

    public function test_delete_removes_the_key(): void
    {
        $this->cache->set('key', 'value');
        $this->cache->delete('key');

        $this->assertNull($this->cache->get('key'));
    }

    public function test_expired_entries_are_not_returned(): void
    {
        $this->cache->set('key', 'value', -1);

        $this->assertNull($this->cache->get('key'));
    }

    public function test_remember_computes_and_caches_on_miss(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return 'computed';
        };

        $first = $this->cache->remember('key', 60, $callback);
        $second = $this->cache->remember('key', 60, $callback);

        $this->assertSame('computed', $first);
        $this->assertSame('computed', $second);
        $this->assertSame(1, $calls);
    }

    public function test_remember_can_cache_a_null_value_without_recomputing(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return null;
        };

        $this->cache->remember('key', 60, $callback);
        $this->cache->remember('key', 60, $callback);

        $this->assertSame(1, $calls);
    }

    public function test_remember_can_cache_a_false_value_without_recomputing(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return false;
        };

        $this->cache->remember('key', 60, $callback);
        $this->cache->remember('key', 60, $callback);

        $this->assertSame(1, $calls);
    }
}
