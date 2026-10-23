<?php

declare(strict_types=1);

namespace CodeVault\Queue;

use CodeVault\Billing\AcceptOrderJob;
use CodeVault\Billing\ChangeServicePasswordJob;
use CodeVault\Billing\CreateAccountJob;
use CodeVault\Billing\ExpiringReminderJob;
use CodeVault\Billing\UpgradePackageJob;
use CodeVault\Mail\SendEmailJob;
use CodeVault\UsernameChanger\UsernameChangeJob;
use Redis;

/**
 * Redis-backed queue (blueprint §3/§4.5 "Redis-backed async"). Jobs are
 * PHP-serialized onto a per-queue list; RPUSH/LPOP gives FIFO ordering.
 */
class RedisQueue implements QueueInterface
{
    /**
     * The ONLY classes unserialize() may materialise from a payload. The
     * queue reads back blobs that were written by this same codebase, but
     * `allowed_classes => true` would happily materialise ANY class a
     * compromised/shared Redis were made to hold — object injection needs a
     * gadget class, and this list is the gadget inventory. A new job class
     * must be added here; QueueJobWhitelistTest fails the build when one is
     * missing, so the list cannot silently drift.
     */
    public const ALLOWED_JOB_CLASSES = [
        AcceptOrderJob::class,
        ChangeServicePasswordJob::class,
        CreateAccountJob::class,
        ExpiringReminderJob::class,
        UpgradePackageJob::class,
        SendEmailJob::class,
        UsernameChangeJob::class,
    ];

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

        $job = unserialize($payload, ['allowed_classes' => self::ALLOWED_JOB_CLASSES]);

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
