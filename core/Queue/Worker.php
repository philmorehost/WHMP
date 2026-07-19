<?php

declare(strict_types=1);

namespace CodeVault\Queue;

use Throwable;

/**
 * Polls a queue and runs jobs one at a time. `maxIterations` exists purely
 * so tests (and a supervisor process that wants bounded runs) can stop the
 * loop deterministically instead of running forever.
 */
class Worker
{
    public function __construct(
        private readonly QueueInterface $queue,
        private readonly string $queueName = 'default',
        private readonly int $sleepMicroseconds = 200_000
    ) {
    }

    /**
     * @return int number of jobs processed
     */
    public function run(?int $maxIterations = null): int
    {
        $processed = 0;
        $iterations = 0;

        while ($maxIterations === null || $iterations < $maxIterations) {
            $iterations++;
            $job = $this->queue->pop($this->queueName);

            if ($job === null) {
                if ($maxIterations !== null) {
                    break;
                }

                usleep($this->sleepMicroseconds);
                continue;
            }

            try {
                $job->handle();
                $processed++;
            } catch (Throwable $e) {
                fwrite(STDERR, sprintf("[%s] job failed: %s\n", date('Y-m-d H:i:s'), $e->getMessage()));
            }
        }

        return $processed;
    }
}
