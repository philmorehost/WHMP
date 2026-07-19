<?php

declare(strict_types=1);

namespace CodeVault\Update;

use CodeVault\Database;
use RuntimeException;
use ZipArchive;

/**
 * Pre-update DB + file backup, and the restore used on rollback (blueprint
 * §5, ported from the DGV `update-guide.md` design). Pure PHP — no
 * `mysqldump`/`exec()` dependency, since target hosts may not allow either.
 */
final class BackupManager
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * @param array<int, string> $excludeRelativeDirs directories (relative to $rootPath) to skip when zipping
     * @return array{filesZip: string, sqlFile: string}
     */
    public function createBackup(string $rootPath, string $backupDir, array $excludeRelativeDirs = []): array
    {
        @mkdir($backupDir, 0755, true);
        $timestamp = date('Y-m-d_H-i-s');

        $sqlFile = $backupDir . "/db_backup_{$timestamp}.sql";
        file_put_contents($sqlFile, $this->dumpDatabase());

        $filesZip = $backupDir . "/files_backup_{$timestamp}.zip";
        $this->zipDirectory($rootPath, $filesZip, array_merge($excludeRelativeDirs, [basename($backupDir)]));

        return ['filesZip' => $filesZip, 'sqlFile' => $sqlFile];
    }

    public function restoreBackup(string $filesZip, string $sqlFile, string $rootPath): void
    {
        if (is_file($filesZip)) {
            $zip = new ZipArchive();

            if ($zip->open($filesZip) === true) {
                $zip->extractTo($rootPath);
                $zip->close();
            }
        }

        if (is_file($sqlFile)) {
            $this->restoreDatabase((string) file_get_contents($sqlFile));
        }
    }

    private function dumpDatabase(): string
    {
        $pdo = $this->db->connection();
        $dump = "-- CodeVault backup: " . date('c') . "\n\n";

        // SHOW TABLES rows come back as ['Tables_in_<dbname>' => 'table'] under
        // the connection's FETCH_ASSOC default, not numeric-indexed — the
        // column name is DB-name-dependent, so grab the value positionally.
        $tables = array_map('current', $this->db->select('SHOW TABLES'));

        foreach ($tables as $table) {
            $createRow = $this->db->selectOne("SHOW CREATE TABLE `{$table}`");
            $createSql = $createRow['Create Table'] ?? $createRow['Create View'] ?? null;

            if ($createSql === null) {
                continue;
            }

            $dump .= "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n";

            foreach ($this->db->select("SELECT * FROM `{$table}`") as $row) {
                $columns = array_map(fn (string $c) => "`{$c}`", array_keys($row));
                $values = array_map(function ($value) use ($pdo) {
                    return $value === null ? 'NULL' : $pdo->quote((string) $value);
                }, array_values($row));

                $dump .= sprintf(
                    "INSERT INTO `%s` (%s) VALUES (%s);\n",
                    $table,
                    implode(', ', $columns),
                    implode(', ', $values)
                );
            }

            $dump .= "\n";
        }

        return $dump;
    }

    private function restoreDatabase(string $sql): void
    {
        $pdo = $this->db->connection();

        foreach (explode(";\n", $sql) as $statement) {
            $statement = trim($statement);

            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }

            try {
                $pdo->exec($statement);
            } catch (\PDOException $e) {
                // Best-effort restore: log and continue rather than abandon
                // the rollback partway through (blueprint §5 "graceful DB failures").
                error_log('Backup restore statement failed: ' . $e->getMessage());
            }
        }
    }

    /** @param array<int, string> $excludeRelativeDirs */
    private function zipDirectory(string $rootPath, string $zipPath, array $excludeRelativeDirs): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create backup archive at [{$zipPath}].");
        }

        $rootPath = rtrim($rootPath, '/\\');
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relativePath = ltrim(str_replace($rootPath, '', $file->getPathname()), '/\\');
            $relativePath = str_replace('\\', '/', $relativePath);

            foreach ($excludeRelativeDirs as $excluded) {
                if (str_starts_with($relativePath, trim($excluded, '/\\') . '/')) {
                    continue 2;
                }
            }

            $zip->addFile($file->getPathname(), $relativePath);
        }

        $zip->close();
    }
}
