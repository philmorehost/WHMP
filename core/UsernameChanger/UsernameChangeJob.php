<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Queue\Job;
use CodeVault\Support\App;

/**
 * Runs one queued username change in the background. Plain data only (the id),
 * so it serialises onto Redis as well as running inline on the sync queue.
 */
final class UsernameChangeJob implements Job
{
    public function __construct(public readonly int $requestId)
    {
    }

    public function queue(): string
    {
        return 'default';
    }

    public function handle(): void
    {
        App::container()->make(UsernameChangeExecutor::class)->run($this->requestId);
    }
}
