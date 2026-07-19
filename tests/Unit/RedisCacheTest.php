<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Cache\RedisCache;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Throwable;

/**
 * Exercises the real Redis-backed cache against the local Redis instance
 * (same host/port the app itself uses) — skips gracefully if Redis isn't
 * reachable so the suite still runs on a box without it, same pattern as
 * DatabaseTestCase for MariaDB.
 */
final class RedisCacheTest extends TestCase
{
    private RedisCache $cache;
    private Redis $raw;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('The redis extension is not loaded.');
        }

        try {
            $this->raw = new Redis();
            $this->raw->connect('127.0.0.1', 6379, 2.5);
            $this->raw->select(0);
        } catch (Throwable $e) {
            $this->markTestSkipped('No local Redis reachable: ' . $e->getMessage());
        }

        $this->cache = new RedisCache('127.0.0.1', 6379, '', 0);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->raw)) {
            $this->cleanUp();
        }
    }

    private function cleanUp(): void
    {
        try {
            $keys = $this->raw->keys('codevault:cache:test:*');

            if ($keys !== false && $keys !== []) {
                $this->raw->del($keys);
            }
        } catch (RedisException) {
        }
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        $this->assertSame('fallback', $this->cache->get('test:missing', 'fallback'));
    }

    public function test_set_then_get_returns_the_stored_value(): void
    {
        $this->cache->set('test:key', ['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $this->cache->get('test:key'));
    }

    public function test_delete_removes_the_key(): void
    {
        $this->cache->set('test:key', 'value');
        $this->cache->delete('test:key');

        $this->assertNull($this->cache->get('test:key'));
    }

    public function test_remember_computes_and_caches_on_miss(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return ['computed' => true];
        };

        $first = $this->cache->remember('test:remember-key', 60, $callback);
        $second = $this->cache->remember('test:remember-key', 60, $callback);

        $this->assertSame(['computed' => true], $first);
        $this->assertSame(['computed' => true], $second);
        $this->assertSame(1, $calls);
    }

    public function test_values_expire_after_their_ttl(): void
    {
        $this->cache->set('test:short-ttl', 'value', 1);

        $ttl = $this->raw->ttl('codevault:cache:test:short-ttl');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(1, $ttl);
    }
}
