<?php

declare(strict_types=1);

namespace CodeVault\Cache;

/**
 * Application-level cache (blueprint §5 "Redis sessions+cache+queue") —
 * a small seam so expensive, low-write-frequency reads (public catalog
 * listings, the sitemap) don't hit the database on every request.
 */
interface Cache
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds = 300): void;

    public function delete(string $key): void;

    /** Returns the cached value, or computes + stores it via $callback if missing. */
    public function remember(string $key, int $ttlSeconds, callable $callback): mixed;
}
