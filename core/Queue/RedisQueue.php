<?php

declare(strict_types=1);

namespace CodeVault\Queue;

use Redis;

/**
 * Redis-backed queue (blueprint §3/§4.5 "Redis-backed async"). Jobs are
 * PHP-serialized onto a per-queue list; RPUSH/LPOP gives FIFO ordering.
 */
class RedisQueue implements QueueInterface
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

    public function push(Job $job): void
    {
        $this->redis->rPush($this->key($job->queue()), serialize($job));
    }

    public function pop(string $queue = 'default'): ?Job
    {
        $payload = $this->redis->lPop($this->key($queue));

        if ($payload === false || $payload === null) {
            return null;
        }

        $job = unserialize($payload, ['allowed_classes' => true]);

        return $job instanceof Job ? $job : null;
    }

    public function size(string $queue = 'default'): int
    {
        return (int) $this->redis->lLen($this->key($queue));
    }

    private function key(string $queue): string
    {
        return "codevault:queue:{$queue}";
    }
}
