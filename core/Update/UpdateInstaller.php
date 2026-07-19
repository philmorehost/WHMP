<?php

declare(strict_types=1);

namespace CodeVault\Update;

use CodeVault\Database;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Applies a downloaded update ZIP (blueprint §5 OTA update system, ported
 * from the DGV `update-guide.md` design): verify checksum, back up,
 * extract to a staging dir (never straight over live files), apply the
 * manifest's file deletions + DB migrations, and roll back automatically
 * on any failure.
 */
final class UpdateInstaller
{
    public function __construct(
        private readonly Database $db,
        private readonly BackupManager $backups,
        private readonly ChecksumVerifier $checksums,
        private readonly string $rootPath,
        private readonly string $backupDir,
        private readonly string $stagingDir
    ) {
    }

    /**
     * @return array{success: bool, message: string, version?: string}
     */
    public function install(string $zipPath, string $expectedChecksum): array
    {
        if (!$this->checksums->verify($zipPath, $expectedChecksum)) {
            return ['success' => false, 'message' => 'Checksum mismatch — download was corrupted or tampered with. Update aborted before touching any files.'];
        }

        $backup = $this->backups->createBackup($this->rootPath, $this->backupDir, [
            basename($this->backupDir),
            basename($this->stagingDir),
            'vendor',
            'storage',
            '.git',
        ]);

        try {
            $this->extract($zipPath, $this->stagingDir);
            $manifest = UpdateManifest::fromFile($this->stagingDir . '/manifest.json');

            $this->deleteObsoleteFiles($manifest->filesToDelete);
            $this->copyStagedFiles($this->stagingDir . '/files', $this->rootPath);
            $this->runMigrations($manifest->databaseQueries);

            $this->cleanup();

            return ['success' => true, 'message' => "Updated to version {$manifest->version}.", 'version' => $manifest->version];
        } catch (Throwable $e) {
            $this->backups->restoreBackup($backup['filesZip'], $backup['sqlFile'], $this->rootPath);
            $this->cleanup();

            return [
                'success' => false,
                'message' => "Update failed ({$e->getMessage()}) — system automatically restored to its previous state.",
            ];
        }
    }

    private function extract(string $zipPath, string $stagingDir): void
    {
        @mkdir($stagingDir, 0755, true);

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Failed to open the downloaded update archive.');
        }

        $zip->extractTo($stagingDir);
        $zip->close();
    }

    /** @param array<int, string> $files */
    private function deleteObsoleteFiles(array $files): void
    {
        foreach ($files as $relativePath) {
            $clean = $this->sanitizeRelativePath($relativePath);
            $target = $this->rootPath . '/' . $clean;

            if ($clean !== '' && is_file($target)) {
                unlink($target);
            }
        }
    }

    private function copyStagedFiles(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $item) {
            $relative = ltrim(str_replace($source, '', $item->getPathname()), '/\\');
            $target = $destination . '/' . str_replace('\\', '/', $relative);

            if ($item->isDir()) {
                @mkdir($target, 0755, true);
                continue;
            }

            @mkdir(dirname($target), 0755, true);
            copy($item->getPathname(), $target);
        }
    }

    /** @param array<int, string> $queries */
    private function runMigrations(array $queries): void
    {
        foreach ($queries as $query) {
            $this->db->connection()->exec($query);
        }
    }

    private function sanitizeRelativePath(string $path): string
    {
        $path = str_replace(['../', '..\\'], '', $path);

        return ltrim($path, '/\\');
    }

    private function cleanup(): void
    {
        if (!is_dir($this->stagingDir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->stagingDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($this->stagingDir);
    }
}
