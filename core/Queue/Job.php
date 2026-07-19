<?php

declare(strict_types=1);

namespace CodeVault\Queue;

/**
 * A unit of deferred work (outbound email, provisioning call, webhook,
 * ...). Must be plain-data-serializable — no closures, no open resources —
 * since it's serialized onto the queue and unserialized by a different
 * PHP process (the worker).
 */
interface Job
{
    public function queue(): string;

    public function handle(): void;
}
