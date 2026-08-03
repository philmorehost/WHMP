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
    /** Jobs at or above this frequency are "daily" and honour the admin's configured run time. */
    private const DAILY_THRESHOLD_MINUTES = 1440;

    /** @var array<int, CronJob> */
    private array $jobs = [];

    private ?CronRunRepository $runs = null;

    private ?CronStateRepository $state = null;

    /** @var callable(): ?string|null Returns the admin's daily run time as "HH:MM", or null for "no constraint". */
    private $dailyRunTimeResolver = null;

    public function __construct(
        private readonly ?string $stateFile = null
    ) {
    }

    /**
     * Optional reporting sink. Kept as a setter rather than a constructor
     * dependency so the scheduler still constructs (and runs) on a system
     * whose schema isn't ready yet — see the class docblock.
     */
    public function recordRunsTo(CronRunRepository $runs): void
    {
        $this->runs = $runs;
    }

    /**
     * Durable store for last-run times, preferred over the JSON state file.
     *
     * The file write was unchecked, so on a host with an unwritable storage/
     * directory the state silently never persisted and every job ran on every
     * tick — daily backups became per-minute backups. The database is
     * writable by definition, so it is the better home for this.
     */
    public function persistStateTo(CronStateRepository $state): void
    {
        $this->state = $state;
    }

    /**
     * Supplies the admin-configured daily automation time ("HH:MM"), as a
     * callable so the setting is read at run time rather than frozen at wiring
     * time. Returning null means daily jobs run purely on elapsed time, which
     * is the pre-existing behaviour.
     *
     * @param callable(): ?string $resolver
     */
    public function useDailyRunTime(callable $resolver): void
    {
        $this->dailyRunTimeResolver = $resolver;
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

        $dailyRunTime = $this->resolveDailyRunTime();

        foreach ($this->jobs as $job) {
            $name = $job->name();
            $lastRun = $state[$name] ?? 0;
            $dueAt = $lastRun + ($job->frequencyMinutes() * 60);

            if ($now < $dueAt) {
                $results[$name] = ['ran' => false];
                continue;
            }

            if (!$this->dailyWindowOpen($job, $dailyRunTime, $lastRun, $now)) {
                $results[$name] = ['ran' => false];
                continue;
            }

            $hooks?->fire(HookPoints::CRON_JOB_STARTED, ['job' => $name]);

            $startedAt = microtime(true);

            try {
                $job->handle();
                $state[$name] = $now;

                // Persist immediately, not just in the final saveState() below.
                // A run killed part-way (a slow backup hitting the host's
                // execution limit) would otherwise record nothing, so the same
                // job restarts every tick and never completes — which is how a
                // daily backup turns into a backup every minute.
                $this->state?->saveOne($name, $now);

                $results[$name] = ['ran' => true];
                $stats = $job instanceof ReportsCronStats ? $job->stats() : [];
                $results[$name]['stats'] = $stats;
                $this->runs?->record($name, 'success', null, $stats, (int) ((microtime(true) - $startedAt) * 1000));
            } catch (Throwable $e) {
                $results[$name] = ['ran' => false, 'error' => $e->getMessage()];
                $this->runs?->record($name, 'error', $e->getMessage(), [], (int) ((microtime(true) - $startedAt) * 1000));
            }

            $hooks?->fire(HookPoints::CRON_JOB_FINISHED, ['job' => $name, 'result' => $results[$name]]);
        }

        $hooks?->fire(HookPoints::DAILY_CRON_JOB, ['results' => $results]);
        $this->saveState($state);

        return $results;
    }

    private function resolveDailyRunTime(): ?string
    {
        if ($this->dailyRunTimeResolver === null) {
            return null;
        }

        try {
            $value = ($this->dailyRunTimeResolver)();
        } catch (Throwable) {
            return null;
        }

        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value) !== 1) {
            // Unset or malformed — fall back to plain elapsed-time scheduling
            // rather than blocking every daily job on a bad setting.
            return null;
        }

        return $value;
    }

    /**
     * WHMCS-style daily automation: the OS cron still fires every minute, but
     * a daily job waits until the admin's configured time of day and then runs
     * once. Sub-daily jobs (domain sync, mail piping) are unaffected.
     *
     * "Has today's slot passed, and haven't I already run since it?" — so a
     * server that was asleep at 00:05 still catches up when it wakes rather
     * than silently skipping a day.
     */
    private function dailyWindowOpen(CronJob $job, ?string $dailyRunTime, int $lastRun, int $now): bool
    {
        if ($dailyRunTime === null || $job->frequencyMinutes() < self::DAILY_THRESHOLD_MINUTES) {
            return true;
        }

        [$hour, $minute] = array_map('intval', explode(':', $dailyRunTime));

        $slotToday = mktime($hour, $minute, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));

        if ($slotToday === false || $now < $slotToday) {
            return false;
        }

        return $lastRun < $slotToday;
    }

    /** @return array<string, int> */
    private function loadState(): array
    {
        $fromDb = $this->state?->load();

        if ($fromDb !== null) {
            return $fromDb;
        }

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
        // Database first: it is the only store guaranteed writable.
        if ($this->state?->save($state) === true) {
            return;
        }

        if ($this->stateFile === null) {
            return;
        }

        // Fallback only. Left unchecked historically, which is exactly how the
        // every-minute backup bug hid: a failed write looked like success.
        $written = @file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));

        if ($written === false) {
            error_log("CronScheduler: could not persist run state to {$this->stateFile} — every job will re-run on the next tick.");
        }
    }
}
