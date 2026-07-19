<?php

declare(strict_types=1);

namespace CodeVault\Cache;

use Redis;
use RedisException;

/**
 * Values are PHP-serialized since a cached value here is usually an
 * array (a query result set), not a scalar — Redis itself only stores
 * strings/binary.
 */
final class RedisCache implements Cache
{
    private Redis $redis;

    public function __construct(string $host, int $port, string $password, int $database)
    {
        $this->redis = new Redis();
        $this->redis->connect($host, $port, 2.5);

        if ($password !== '') {
            $this->redis->auth($password);
        }

        $this->redis->select($database);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = $this->redis->get($this->prefixed($key));

            return $value === false ? $default : unserialize($value);
        } catch (RedisException) {
            return $default;
        }
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 300): void
    {
        try {
            $this->redis->setex($this->prefixed($key), $ttlSeconds, serialize($value));
        } catch (RedisException) {
            // Cache is a performance optimization, not a correctness
            // requirement — a write failure here shouldn't break the request.
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->redis->del($this->prefixed($key));
        } catch (RedisException) {
        }
    }

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $miss = new \stdClass();
        $cached = $this->get($key, $miss);

        if ($cached !== $miss) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttlSeconds);

        return $value;
    }

    private function prefixed(string $key): string
    {
        return "codevault:cache:{$key}";
    }
}
