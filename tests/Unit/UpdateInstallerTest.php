<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\Update\BackupManager;
use CodeVault\Update\ChecksumVerifier;
use CodeVault\Update\UpdateInstaller;
use ZipArchive;

final class UpdateInstallerTest extends DatabaseTestCase
{
    private string $workDir;
    private string $rootPath;
    private string $backupDir;
    private string $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->connection()->exec('CREATE TABLE demo (id INT PRIMARY KEY, value VARCHAR(50))');
        $this->db->insert('INSERT INTO demo (id, value) VALUES (1, ?)', ['v1']);

        $this->workDir = sys_get_temp_dir() . '/codevault-update-test-' . uniqid();
        $this->rootPath = $this->workDir . '/root';
        $this->backupDir = $this->workDir . '/backups';
        $this->stagingDir = $this->workDir . '/staging';

        mkdir($this->rootPath, 0777, true);
        file_put_contents($this->rootPath . '/app.txt', 'v1');
        file_put_contents($this->rootPath . '/old-file.txt', 'obsolete');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->workDir);
    }

    private function installer(): UpdateInstaller
    {
        return new UpdateInstaller(
            $this->db,
            new BackupManager($this->db),
            new ChecksumVerifier(),
            $this->rootPath,
            $this->backupDir,
            $this->stagingDir,
        );
    }

    /**
     * @param array<int, string> $filesToDelete
     * @param array<int, string> $databaseQueries
     */
    private function buildUpdateZip(string $appTxtContent, array $filesToDelete, array $databaseQueries): string
    {
        $zipPath = $this->workDir . '/update.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode([
            'version' => '1.1.0',
            'files_to_delete' => $filesToDelete,
            'database_queries' => $databaseQueries,
        ]));
        $zip->addFromString('files/app.txt', $appTxtContent);
        $zip->close();

        return $zipPath;
    }

    public function test_successful_update_applies_files_deletions_and_migrations(): void
    {
        $zipPath = $this->buildUpdateZip('v2', ['old-file.txt'], ["UPDATE demo SET value='v2' WHERE id=1"]);
        $checksum = hash_file('sha256', $zipPath);

        $result = $this->installer()->install($zipPath, $checksum);

        $this->assertTrue($result['success']);
        $this->assertSame('1.1.0', $result['version']);
        $this->assertSame('v2', file_get_contents($this->rootPath . '/app.txt'));
        $this->assertFileDoesNotExist($this->rootPath . '/old-file.txt');
        $this->assertSame('v2', $this->db->selectOne('SELECT value FROM demo WHERE id = 1')['value']);
    }

    public function test_checksum_mismatch_aborts_before_touching_any_files(): void
    {
        $zipPath = $this->buildUpdateZip('v2', ['old-file.txt'], []);

        $result = $this->installer()->install($zipPath, str_repeat('0', 64));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Checksum mismatch', $result['message']);
        $this->assertSame('v1', file_get_contents($this->rootPath . '/app.txt'));
        $this->assertFileExists($this->rootPath . '/old-file.txt');
    }

    public function test_failed_migration_triggers_automatic_rollback(): void
    {
        $zipPath = $this->buildUpdateZip('v2', ['old-file.txt'], ['THIS IS NOT VALID SQL AT ALL']);
        $checksum = hash_file('sha256', $zipPath);

        $result = $this->installer()->install($zipPath, $checksum);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('restored', $result['message']);

        // Files and DB should both be back to their pre-update state.
        $this->assertSame('v1', file_get_contents($this->rootPath . '/app.txt'));
        $this->assertFileExists($this->rootPath . '/old-file.txt');
        $this->assertSame('v1', $this->db->selectOne('SELECT value FROM demo WHERE id = 1')['value']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
