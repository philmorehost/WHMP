<?php

declare(strict_types=1);

namespace CodeVault\Tests\Support;

use CodeVault\Mail\SendEmailJob;
use CodeVault\Queue\Job;
use CodeVault\Queue\QueueInterface;

/** Collects pushed jobs instead of running them, so the test can read what would be sent. */
final class CapturingQueue implements QueueInterface
{
    /** @var array<int, Job> */
    public array $jobs = [];

    public function push(Job $job): void
    {
        $this->jobs[] = $job;
    }

    public function pop(string $queue = 'default'): ?Job
    {
        return array_shift($this->jobs);
    }

    public function size(string $queue = 'default'): int
    {
        return count($this->jobs);
    }

    public function only(): SendEmailJob
    {
        if (count($this->jobs) !== 1 || !$this->jobs[0] instanceof SendEmailJob) {
            throw new \RuntimeException('expected exactly one queued email, got ' . count($this->jobs));
        }

        return $this->jobs[0];
    }
}
