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
 *
 * Jobs may be registered eagerly (register) or lazily (registerLazy). A
 * lazy registration carries only the job's name and cadence; the object —
 * and the whole dependency graph behind it — is built on the first tick
 * the job is actually due. That keeps a per-minute cron tick cheap: on an
 * average tick nothing is due, so nothing is constructed and nothing is
 * written; the tick's cost is one DB read plus a few checks.
 */
class CronScheduler
{
    /** Jobs at or above this frequency are "daily" and honour the admin's configured run time. */
    private const DAILY_THRESHOLD_MINUTES = 1440;

    /** @var array<int, CronJob> */
    private array $jobs = [];

    /**
     * Deferred registrations, in registration order:
     * `['name' => string, 'freq' => int, 'factory' => callable(): CronJob]`.
     *
     * @var array<int, array{name: string, freq: int, factory: callable}>
     */
    private array $lazyJobs = [];

    private ?CronRunRepository $runs = null;

    private ?CronStateRepository $state = null;

    /**
     * Set when the DB store answered "exists but empty" while the JSON file
     * still held timestamps — an upgrade from the file-era scheduler. The
     * sweep then runs on the file's times and seeds the DB once afterwards.
     */
    private bool $seedPending = false;

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

    /**
     * Registers a job by metadata, deferring its construction until a tick
     * where it is actually due. `$factory` receives the container-free
     * closure from the entry point and must return the CronJob.
     *
     * The name/cadence here must mirror the job class; if they ever drift,
     * the scheduler notices on materialisation, trusts the real object and
     * re-gates against its real `name()`/`frequencyMinutes()` before running
     * anything — so drift can delay a run by at most one tick, never skip or
     * double it.
     *
     * @param callable(): CronJob $factory
     */
    public function registerLazy(string $name, int $frequencyMinutes, callable $factory): void
    {
        $this->lazyJobs[] = ['name' => $name, 'freq' => $frequencyMinutes, 'factory' => $factory];
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

        // Only the jobs that actually ran this tick change persisted state.
        // End-of-tick writes used to save the entire table unconditionally —
        // ~35 upserts per minute, ~50,000 a day, virtually all no-ops.
        $changed = [];
        $allPersisted = true;

        foreach ($this->entries() as $entry) {
            $registeredName = $entry['name'];

            // Cheap gate on registration metadata first, so a lazy job's
            // object graph is never built for a tick it cannot run in.
            if (!$this->isDue($entry['freq'], $state[$registeredName] ?? 0, $dailyRunTime, $now)) {
                $results[$registeredName] = ['ran' => false];
                continue;
            }

            $job = $entry['job'];

            if ($job === null) {
                try {
                    $job = ($entry['factory'])();

                    if (!$job instanceof CronJob) {
                        throw new \RuntimeException(sprintf(
                            'lazy factory returned %s, expected a CronJob',
                            get_debug_type($job)
                        ));
                    }
                } catch (Throwable $e) {
                    // Construction failed — same handling as a failing job:
                    // report, don't touch state, retry next tick.
                    $results[$registeredName] = ['ran' => false, 'error' => $e->getMessage()];
                    $this->runs?->record($registeredName, 'error', $e->getMessage(), [], 0);
                    continue;
                }

                $name = $job->name();

                if ($name !== $registeredName) {
                    error_log(sprintf(
                        'CronScheduler: lazy registration "%s" resolved to job "%s" — trusting the job class.',
                        $registeredName,
                        $name
                    ));
                }

                // Re-gate on the job's own metadata, which is authoritative:
                // a stale registered cadence (e.g. a settings-driven one) must
                // not make the job run early.
                if (!$this->isDue($job->frequencyMinutes(), $state[$name] ?? 0, $dailyRunTime, $now)) {
                    $results[$name] = ['ran' => false];
                    continue;
                }
            } else {
                $name = $job->name();
            }

            $hooks?->fire(HookPoints::CRON_JOB_STARTED, ['job' => $name]);

            $startedAt = microtime(true);

            try {
                $job->handle();
                $state[$name] = $now;
                $changed[$name] = true;

                // Persist immediately, not just in the final saveState() below.
                // A run killed part-way (a slow backup hitting the host's
                // execution limit) would otherwise record nothing, so the same
                // job restarts every tick and never completes — which is how a
                // daily backup turns into a backup every minute.
                if ($this->state?->saveOne($name, $now) === false) {
                    $allPersisted = false;
                }

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

        // A tick that ran nothing must not write anything either.
        if ($changed !== [] && ($this->state === null || !$allPersisted)) {
            $this->saveState($state, array_keys($changed));
        }

        // One-time migration: the DB store exists but was empty while the
        // JSON file still had timestamps. Persist the file-era times so the
        // upgrade doesn't treat every job as never-run (the old scheduler
        // did this accidentally, by rewriting the full table every tick).
        if ($this->seedPending && $this->state !== null) {
            $this->seedPending = $this->state->save($state) !== true;
        }

        return $results;
    }

    /**
     * Every registration as a uniform entry, eager ones first, in the order
     * they were registered (order matters: the activity report must see what
     * the jobs above it recorded).
     *
     * @return array<int, array{name: string, freq: int, job: CronJob|null, factory: callable|null}>
     */
    private function entries(): array
    {
        $out = [];

        foreach ($this->jobs as $job) {
            $out[] = ['name' => $job->name(), 'freq' => $job->frequencyMinutes(), 'job' => $job, 'factory' => null];
        }

        foreach ($this->lazyJobs as $spec) {
            $out[] = ['name' => $spec['name'], 'freq' => (int) $spec['freq'], 'job' => null, 'factory' => $spec['factory']];
        }

        return $out;
    }

    /**
     * May the job run on this tick? Elapsed-time gate plus, for daily jobs,
     * the admin's configured automation window.
     */
    private function isDue(int $frequencyMinutes, int $lastRun, ?string $dailyRunTime, int $now): bool
    {
        if ($now < $lastRun + ($frequencyMinutes * 60)) {
            return false;
        }

        return $this->dailyWindowOpen($frequencyMinutes, $dailyRunTime, $lastRun, $now);
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
    private function dailyWindowOpen(int $frequencyMinutes, ?string $dailyRunTime, int $lastRun, int $now): bool
    {
        if ($dailyRunTime === null || $frequencyMinutes < self::DAILY_THRESHOLD_MINUTES) {
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

        if ($fromDb !== null && $fromDb !== []) {
            return $fromDb;
        }

        $fromFile = [];

        if ($this->stateFile !== null && is_file($this->stateFile)) {
            $contents = file_get_contents($this->stateFile);
            $decoded = $contents === false ? [] : json_decode($contents, true);

            if (is_array($decoded)) {
                /** @var array<string, int> $decoded */
                $fromFile = $decoded;
            }
        }

        if ($fromDb !== null) {
            // The store exists but is empty: a fresh install, or an upgrade
            // from the JSON-file era. If the file still holds timestamps, run
            // on them and seed the store once the sweep is done — without
            // that, the first tick after an upgrade would treat every job
            // as never-run.
            $this->seedPending = $fromFile !== [];

            return $fromFile;
        }

        return $fromFile;
    }

    /**
     * @param array<string, int> $state
     * @param array<int, string>|null $onlyKeys restrict the DB write to these job
     *        names — the keys that actually changed. The JSON file fallback always
     *        writes the full snapshot; the file is read as a whole.
     */
    private function saveState(array $state, ?array $onlyKeys = null): void
    {
        $forDb = $onlyKeys === null ? $state : array_intersect_key($state, array_fill_keys($onlyKeys, true));

        // Database first: it is the only store guaranteed writable.
        if ($forDb !== [] && $this->state?->save($forDb) === true) {
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
