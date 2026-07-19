<?php

declare(strict_types=1);

namespace CodeVault\Queue;

/**
 * Fallback queue for when Redis isn't available (local dev, tests): runs
 * the job inline instead of deferring it. Same interface as RedisQueue so
 * calling code never branches on which one it got.
 */
class SyncQueue implements QueueInterface
{
    public function push(Job $job): void
    {
        $job->handle();
    }

    public function pop(string $queue = 'default'): ?Job
    {
        return null;
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }
}
