<?php

declare(strict_types=1);

namespace CodeVault\Queue;

interface QueueInterface
{
    public function push(Job $job): void;

    /**
     * Pop and return the next job, or null if the queue is empty.
     */
    public function pop(string $queue = 'default'): ?Job;

    public function size(string $queue = 'default'): int;
}
