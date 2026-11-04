<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Backup\BackupRunRepository;
use CodeVault\Backup\BackupService;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\Update\BackupManager;

final class BackupServiceTest extends DatabaseTestCase
{
    private string $rootPath;
    private string $backupDir;
    private BackupRunRepository $runs;
    private BackupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        // A small throwaway tree, not the real project — zipping the whole
        // repo per test run would be slow and is not what's under test here.
        $this->rootPath = sys_get_temp_dir() . '/codevault-backup-test-' . uniqid();
        mkdir($this->rootPath);
        file_put_contents($this->rootPath . '/hello.txt', 'hi');
        $this->backupDir = $this->rootPath . '/backups';

        $this->runs = new BackupRunRepository($this->db);
        $this->service = new BackupService(new BackupManager($this->db), $this->runs, $this->rootPath, $this->backupDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootPath);
        parent::tearDown();
    }

    public function test_run_is_skipped_while_another_backup_holds_the_lock(): void
    {
        mkdir($this->backupDir, 0755, true);
        $handle = fopen($this->backupDir . '/.backup.lock', 'c');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'test could not take the lock it just created');

        $runId = $this->service->run();

        $this->assertSame(0, $runId, 'a concurrent run must be skipped, not started');
        $this->assertSame([], $this->runs->all(), 'the skipped run must not leave a history row');

        // Releasing the lock lets the next run through — a held lock can only
        // ever delay a backup, never wedge the system.
        flock($handle, LOCK_UN);
        fclose($handle);
        $this->assertGreaterThan(0, $this->service->run());
    }

    public function test_run_releases_its_lock_when_it_finishes(): void
    {
        // Back-to-back runs in one process: the second must not be treated as
        // an overlap of the first. (This is exactly how the old every-minute
        // schedule stacked copies — a run that never let go of the lock, or
        // worse, never checked it.)
        $first = $this->service->run();
        $second = $this->service->run();

        $this->assertGreaterThan(0, $first);
        $this->assertGreaterThan($first, $second, 'the second run executed and recorded its own history row');
    }

    public function test_terminate_deletes_stuck_running_rows_and_their_partial_files(): void
    {
        // A completed backup first — terminate must never touch it.
        $this->assertGreaterThan(0, $this->service->run());
        $goodFiles = glob($this->backupDir . '/*backup_*');
        $this->assertCount(2, $goodFiles);

        // Now the mess: a run killed mid-dump leaves its row in 'running'
        // plus half-written artefacts stamped after started_at. The row is
        // shifted +5min so its cleanup window provably cannot overlap the
        // just-finished healthy run's files even within the same second.
        $this->runs->start();
        $running = $this->runs->allRunning();
        $this->assertCount(1, $running);

        $shifted = (new \DateTimeImmutable((string) $running[0]['started_at']))
            ->modify('+5 minutes')
            ->format('Y-m-d H:i:s');
        $this->db->update("UPDATE backup_runs SET started_at = ? WHERE status = 'running'", [$shifted]);

        $ts = str_replace([' ', ':'], ['_', '-'], $shifted);
        file_put_contents($this->backupDir . "/db_backup_{$ts}.sql", 'partial dump');
        file_put_contents($this->backupDir . "/files_backup_{$ts}.zip", 'partial zip');

        $result = $this->service->terminateRunning();

        $this->assertSame(1, $result['terminated']);
        $this->assertSame(2, $result['files']);
        $this->assertFalse($result['live'], 'nothing holds the run lock in this test');
        $this->assertSame([], $this->runs->allRunning());

        // The completed run's row and files survive untouched.
        $this->assertCount(1, $this->runs->all());
        $this->assertSame('success', $this->runs->all()[0]['status']);
        foreach ($goodFiles as $file) {
            $this->assertFileExists($file);
        }
    }

    public function test_terminate_reports_a_live_backup_and_still_cleans_its_row(): void
    {
        mkdir($this->backupDir, 0755, true);
        $handle = fopen($this->backupDir . '/.backup.lock', 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'test could not take the lock it just created');

        $this->runs->start();

        $result = $this->service->terminateRunning();

        $this->assertTrue($result['live'], 'a held run lock must be reported as a live backup');
        $this->assertSame(1, $result['terminated'], 'the stuck row is still closed out');
        $this->assertSame([], $this->runs->allRunning());

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function test_terminate_with_nothing_running_is_a_noop(): void
    {
        $this->assertGreaterThan(0, $this->service->run());
        $filesBefore = glob($this->backupDir . '/*backup_*');

        $result = $this->service->terminateRunning();

        $this->assertSame(0, $result['terminated']);
        $this->assertSame(0, $result['files']);
        $this->assertFalse($result['live']);
        $this->assertSame($filesBefore, glob($this->backupDir . '/*backup_*'));
        $this->assertCount(1, $this->runs->all());
    }

    public function test_run_records_a_successful_backup_with_a_positive_size(): void
    {
        $runId = $this->service->run();

        $run = $this->runs->all()[0];
        $this->assertSame($runId, (int) $run['id']);
        $this->assertSame('success', $run['status']);
        $this->assertGreaterThan(0, (int) $run['size_bytes']);
        $this->assertNotNull($run['finished_at']);
    }

    public function test_run_actually_produces_a_sql_dump_and_a_files_zip_on_disk(): void
    {
        $this->service->run();

        $files = glob($this->backupDir . '/*');
        $sqlFiles = array_values(array_filter($files, static fn (string $f) => str_ends_with($f, '.sql')));
        $zipFiles = array_filter($files, static fn (string $f) => str_ends_with($f, '.zip'));

        $this->assertNotEmpty($sqlFiles);
        $this->assertNotEmpty($zipFiles);

        // Regression: the dump must contain real table definitions and data,
        // not just the header comment — a prior bug (array_column() against
        // SHOW TABLES' associative FETCH_ASSOC rows) silently produced an
        // empty dump that still passed a bare "file exists" check.
        $sql = file_get_contents($sqlFiles[0]);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('currencies', $sql, 'the seeded USD currency row\'s table must appear in the dump');
        $this->assertStringContainsString("INSERT INTO `currencies`", $sql);
    }

    public function test_run_marks_the_run_as_running_before_completing(): void
    {
        // start() alone (no completion) is exactly the "running" state a
        // still-in-progress backup would show in the admin history table.
        $runId = $this->runs->start();

        $run = $this->runs->all()[0];
        $this->assertSame($runId, (int) $run['id']);
        $this->assertSame('running', $run['status']);
        $this->assertNull($run['finished_at']);
    }

    public function test_fail_records_the_error_message(): void
    {
        $runId = $this->runs->start();
        $this->runs->fail($runId, 'disk full');

        $run = $this->runs->all()[0];
        $this->assertSame('failed', $run['status']);
        $this->assertSame('disk full', $run['error']);
    }

    public function test_all_returns_most_recent_run_first(): void
    {
        $this->service->run();
        $this->service->run();

        $runs = $this->runs->all();
        $this->assertCount(2, $runs);
        $this->assertGreaterThan((int) $runs[1]['id'], (int) $runs[0]['id']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}
