<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Cron\CronJob;
use CodeVault\Cron\CronScheduler;
use PHPUnit\Framework\TestCase;

/**
 * The scheduler's resource behaviour: a lazy job is not even constructed on
 * a tick where it is not due, an idle tick persists nothing, and a lazy
 * registration whose name/cadence drifted from the job class is corrected
 * against the real object before anything runs.
 */
final class CronSchedulerTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/cron-scheduler-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
    }

    public function testLazyJobIsNotConstructedWhileNotDue(): void
    {
        file_put_contents($this->stateFile, json_encode(['weekly' => time()]));

        $scheduler = new CronScheduler($this->stateFile);

        $constructed = 0;
        $scheduler->registerLazy('weekly', 10080, function () use (&$constructed): CronJob {
            $constructed++;

            return $this->job('weekly', 10080);
        });

        $results = $scheduler->run();

        $this->assertSame(0, $constructed, 'a not-due lazy job must not be constructed');
        $this->assertFalse($results['weekly']['ran']);
    }

    public function testLazyJobRunsWhenDueAndWaitsItsCadence(): void
    {
        file_put_contents($this->stateFile, json_encode(['hourly' => time() - 7200]));

        $scheduler = new CronScheduler($this->stateFile);

        $constructed = 0;
        $job = null;
        $scheduler->registerLazy('hourly', 60, function () use (&$constructed, &$job): CronJob {
            $constructed++;

            return $job = $this->job('hourly', 60);
        });

        $results = $scheduler->run();

        $this->assertSame(1, $constructed);
        $this->assertSame(1, $job->handled);
        $this->assertTrue($results['hourly']['ran']);

        // The run time was persisted immediately, so the next tick — even one
        // that crashes part-way through the sweep — will not re-run the job.
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertGreaterThan(time() - 10, $state['hourly']);

        $results = $scheduler->run();

        $this->assertSame(1, $constructed, 'a just-run lazy job must not be reconstructed next tick');
        $this->assertSame(1, $job->handled);
        $this->assertFalse($results['hourly']['ran']);
    }

    public function testIdleTickWritesNothing(): void
    {
        // Seeded "just ran" + a 10-year cadence: nothing can possibly be due.
        file_put_contents($this->stateFile, json_encode(['never' => time()]));

        $scheduler = new CronScheduler($this->stateFile);

        $constructed = 0;
        $scheduler->registerLazy('never', 5256000, function () use (&$constructed): CronJob {
            $constructed++;

            return $this->job('never', 5256000);
        });

        $before = (string) file_get_contents($this->stateFile);
        $results = $scheduler->run();

        $this->assertFalse($results['never']['ran']);
        $this->assertSame(0, $constructed, 'an idle tick must not construct anything');
        $this->assertSame($before, (string) file_get_contents($this->stateFile), 'an idle tick must not rewrite state');
    }

    public function testIdleTickDoesNotRewriteExistingState(): void
    {
        file_put_contents($this->stateFile, json_encode(['daily' => time()]));

        $scheduler = new CronScheduler($this->stateFile);
        $scheduler->registerLazy('daily', 1440, fn (): CronJob => $this->job('daily', 1440));

        $before = (string) file_get_contents($this->stateFile);
        $scheduler->run();

        $this->assertSame($before, (string) file_get_contents($this->stateFile));
    }

    public function testStaleCadenceIsReGatedAgainstTheRealJob(): void
    {
        // Registration says every minute; the real job says every 30 days and
        // its real name ran moments ago. The cheap pre-gate passes on the
        // stale metadata, the job is materialised — then the real cadence
        // must stop it from running.
        file_put_contents($this->stateFile, json_encode(['real-name' => time()]));

        $scheduler = new CronScheduler($this->stateFile);

        $job = null;
        $scheduler->registerLazy('stale-name', 1, function () use (&$job): CronJob {
            return $job = $this->job('real-name', 43200);
        });

        $results = $scheduler->run();

        $this->assertInstanceOf(CronJob::class, $job, 'the job is constructed once the cheap gate passes');
        $this->assertSame(0, $job->handled, 'the real cadence must veto the stale registration');
        $this->assertFalse($results['real-name']['ran']);
    }

    public function testDriftedNameIsPersistedUnderTheRealName(): void
    {
        $scheduler = new CronScheduler($this->stateFile);

        $job = null;
        $scheduler->registerLazy('stale-name', 1, function () use (&$job): CronJob {
            return $job = $this->job('real-name', 1);
        });

        $results = $scheduler->run();

        $this->assertTrue($results['real-name']['ran'], 'results are keyed by the job\'s real name');
        $this->assertArrayNotHasKey('stale-name', $results);

        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertArrayHasKey('real-name', $state);
        $this->assertArrayNotHasKey('stale-name', $state);
    }

    public function testFailingFactoryIsReportedAndRetriedNextTick(): void
    {
        $scheduler = new CronScheduler($this->stateFile);

        $attempts = 0;
        $scheduler->registerLazy('boom', 0, function () use (&$attempts): CronJob {
            $attempts++;

            throw new \RuntimeException('container exploded');
        });

        $results = $scheduler->run();

        $this->assertFalse($results['boom']['ran']);
        $this->assertSame('container exploded', $results['boom']['error']);
        $this->assertFileDoesNotExist($this->stateFile, 'a failed construction must not record a run');

        $results = $scheduler->run();

        $this->assertSame(2, $attempts, 'a failed construction is retried, never recorded as done');
        $this->assertSame('container exploded', $results['boom']['error']);
    }

    public function testEagerJobsStillRunAndPersist(): void
    {
        $scheduler = new CronScheduler($this->stateFile);

        $job = $this->job('eager', 0);
        $scheduler->register($job);

        $scheduler->run();
        $this->assertSame(1, $job->handled);

        $scheduler->run();
        $this->assertSame(2, $job->handled, 'a 0-minute cadence is due every tick');

        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertGreaterThan(time() - 10, $state['eager']);
    }

    /**
     * @return CronJob&object{handled: int}
     */
    private function job(string $name, int $frequencyMinutes): CronJob
    {
        return new class ($name, $frequencyMinutes) implements CronJob {
            public int $handled = 0;

            public function __construct(
                private readonly string $jobName,
                private readonly int $frequency
            ) {
            }

            public function name(): string
            {
                return $this->jobName;
            }

            public function frequencyMinutes(): int
            {
                return $this->frequency;
            }

            public function handle(): void
            {
                $this->handled++;
            }
        };
    }
}
