<?php

declare(strict_types=1);

namespace CodeVault\Cache;

/**
 * Request-scoped fallback when Redis is unreachable (local dev box,
 * extension missing) — same resilience shape as SessionManager's file-
 * session fallback. Provides no cross-request benefit on its own, but
 * keeps remember() callers correct rather than requiring a Redis
 * dependency to run at all.
 */
final class ArrayCache implements Cache
{
    /** @var array<string, array{value: mixed, expiresAt: int}> */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->items[$key] ?? null;

        if ($entry === null || $entry['expiresAt'] < time()) {
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 300): void
    {
        $this->items[$key] = ['value' => $value, 'expiresAt' => time() + $ttlSeconds];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
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
}
