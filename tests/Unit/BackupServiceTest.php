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
