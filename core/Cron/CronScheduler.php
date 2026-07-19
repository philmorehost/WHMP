<?php

declare(strict_types=1);

namespace CodeVault\Cron;

use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use Throwable;

/**
 * The single system cron (blueprint §3) — one registry of CronJob
 * implementations, run from bin/cron.php on one real OS cron entry.
 * Last-run times persist to a small JSON state file rather than a DB
 * table, so the scheduler itself has zero dependency on the schema that
 * later phases will still be building.
 */
class CronScheduler
{
    /** @var array<int, CronJob> */
    private array $jobs = [];

    public function __construct(
        private readonly ?string $stateFile = null
    ) {
    }

    public function register(CronJob $job): void
    {
        $this->jobs[] = $job;
    }

    /** @return array<int, CronJob> */
    public function jobs(): array
    {
        return $this->jobs;
    }

    /**
     * @return array<string, array{ran: bool, error?: string}> per-job outcome
     */
    public function run(?HookDispatcher $hooks = null): array
    {
        $now = time();
        $state = $this->loadState();
        $results = [];

        foreach ($this->jobs as $job) {
            $name = $job->name();
            $lastRun = $state[$name] ?? 0;
            $dueAt = $lastRun + ($job->frequencyMinutes() * 60);

            if ($now < $dueAt) {
                $results[$name] = ['ran' => false];
                continue;
            }

            $hooks?->fire(HookPoints::CRON_JOB_STARTED, ['job' => $name]);

            try {
                $job->handle();
                $state[$name] = $now;
                $results[$name] = ['ran' => true];
            } catch (Throwable $e) {
                $results[$name] = ['ran' => false, 'error' => $e->getMessage()];
            }

            $hooks?->fire(HookPoints::CRON_JOB_FINISHED, ['job' => $name, 'result' => $results[$name]]);
        }

        $hooks?->fire(HookPoints::DAILY_CRON_JOB, ['results' => $results]);
        $this->saveState($state);

        return $results;
    }

    /** @return array<string, int> */
    private function loadState(): array
    {
        if ($this->stateFile === null || !is_file($this->stateFile)) {
            return [];
        }

        $contents = file_get_contents($this->stateFile);
        $decoded = $contents === false ? [] : json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, int> $state */
    private function saveState(array $state): void
    {
        if ($this->stateFile === null) {
            return;
        }

        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }
}
