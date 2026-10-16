<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Backup\BackupCronJob;
use CodeVault\Backup\BackupRunRepository;
use CodeVault\Backup\BackupService;
use CodeVault\Database\Migrator;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\Update\BackupManager;

/**
 * The automated backup's schedule and master switch.
 *
 * The job used to honour a 1-hour floor — and with unpersisted scheduler
 * state it effectively ran every minute, stacking concurrent copies of a
 * full database dump plus an install zip until the server fell over. These
 * tests pin the two guarantees that must never regress: daily is the
 * fastest the automated backup may run, and the admin's switch stops the
 * cron from running it at all.
 */
final class BackupCronJobTest extends DatabaseTestCase
{
    private SettingsRepository $settings;
    private BackupRunRepository $runs;
    private string $rootPath;
    private string $backupDir;
    private BackupCronJob $job;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->settings = new SettingsRepository($this->db);
        $this->runs = new BackupRunRepository($this->db);

        // Never used while the tests below run — handle() either no-ops
        // (disabled) or is never reached (frequency tests); the tree exists
        // only so constructing the service can't accidentally write anywhere
        // real if a future change makes it run.
        $this->rootPath = sys_get_temp_dir() . '/codevault-backup-job-' . uniqid();
        mkdir($this->rootPath);
        $this->backupDir = $this->rootPath . '/backups';

        $service = new BackupService(new BackupManager($this->db), $this->runs, $this->rootPath, $this->backupDir, $this->settings);
        $this->job = new BackupCronJob($service, $this->settings);
    }

    protected function tearDown(): void
    {
        @unlink($this->rootPath . '/hello.txt');
        @rmdir($this->rootPath);
        parent::tearDown();
    }

    public function test_frequency_defaults_to_once_per_day(): void
    {
        $this->assertSame(1440, $this->job->frequencyMinutes());
    }

    public function test_frequency_is_floored_at_one_day(): void
    {
        // The exact configuration that let the backup system eat a server.
        $this->settings->set('backup.frequency_hours', '1');
        $this->assertSame(1440, $this->job->frequencyMinutes(), 'hourly must clamp to daily');

        $this->settings->set('backup.frequency_hours', '0.5');
        $this->assertSame(1440, $this->job->frequencyMinutes());

        $this->settings->set('backup.frequency_hours', '0');
        $this->assertSame(1440, $this->job->frequencyMinutes(), 'a zero setting must not make the job due every tick');
    }

    public function test_frequency_can_be_less_frequent_than_daily(): void
    {
        $this->settings->set('backup.frequency_hours', '48');
        $this->assertSame(2880, $this->job->frequencyMinutes());

        $this->settings->set('backup.frequency_hours', '168');
        $this->assertSame(10080, $this->job->frequencyMinutes(), '168 = weekly still works');
    }

    public function test_blank_or_garbage_frequency_falls_back_to_daily(): void
    {
        $this->settings->set('backup.frequency_hours', '');
        $this->assertSame(1440, $this->job->frequencyMinutes());

        $this->settings->set('backup.frequency_hours', 'whenever');
        $this->assertSame(1440, $this->job->frequencyMinutes());
    }

    public function test_backups_are_enabled_by_default(): void
    {
        $this->assertTrue($this->job->isEnabled());
    }

    public function test_admin_can_disable_the_backup_system(): void
    {
        $this->settings->set('backup.enabled', '0');

        $this->assertFalse($this->job->isEnabled());
    }

    public function test_disabled_job_never_runs_a_backup(): void
    {
        $this->settings->set('backup.enabled', '0');

        // Must be a silent no-op: no run row, no backup files.
        $this->job->handle();

        $this->assertSame([], $this->runs->all(), 'no backup_runs row may appear for a disabled system');
        $this->assertFileDoesNotExist($this->backupDir . '/.backup.lock');
    }

    public function test_enabled_job_reports_enabled_after_re_enabling(): void
    {
        $this->settings->set('backup.enabled', '0');
        $this->assertFalse($this->job->isEnabled());

        $this->settings->set('backup.enabled', '1');
        $this->assertTrue($this->job->isEnabled(), 'the switch works both ways without a restart');
    }
}
