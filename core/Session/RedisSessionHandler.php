<?php

declare(strict_types=1);

namespace CodeVault\Session;

use Redis;
use RedisException;
use SessionHandlerInterface;

/**
 * Redis-backed session storage. Kept isolated behind SessionHandlerInterface
 * so SessionManager can fall back to native file sessions transparently
 * (e.g. local dev boxes without a Redis server) — see §2/§4.5 of the blueprint.
 */
class RedisSessionHandler implements SessionHandlerInterface
{
    private Redis $redis;

    private int $ttl;

    public function __construct(string $host, int $port, string $password, int $database, int $ttlSeconds = 7200)
    {
        $this->redis = new Redis();

        // connect() returns false (rather than throwing) on some failures;
        // treat that as unusable so SessionManager falls back to file
        // sessions instead of proceeding with a dead handler.
        if (!$this->redis->connect($host, $port, 2.5)) {
            throw new RedisException("Redis connect to {$host}:{$port} failed");
        }

        if ($password !== '') {
            $this->redis->auth($password);
        }

        $this->redis->select($database);

        // Verify the connection is actually usable with a real round-trip.
        // Without this, a Redis that accepts the TCP connection but rejects
        // commands (auth required but none/empty configured, wrong DB,
        // memory-full noeviction, a non-Redis service on the port) would
        // pass construction, then silently fail every read()/write() — and
        // because those swallow RedisException, sessions would vanish on
        // every request with no fallback: CSRF tokens and logins would
        // never persist. Failing here instead lets SessionManager catch it
        // and use rock-solid native file sessions.
        if ($this->redis->ping() === false) {
            throw new RedisException('Redis ping failed — connection not usable');
        }

        $this->ttl = $ttlSeconds;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $value = $this->redis->get($this->key($id));

            return $value === false ? '' : $value;
        } catch (RedisException) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            return (bool) $this->redis->setex($this->key($id), $this->ttl, $data);
        } catch (RedisException) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->redis->del($this->key($id));

            return true;
        } catch (RedisException) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis TTLs expire keys on their own; nothing to sweep.
        return 0;
    }

    private function key(string $id): string
    {
        return "codevault:session:{$id}";
    }
}
