<?php

declare(strict_types=1);

namespace CodeVault\Backup;

use CodeVault\Database;
use DateTimeImmutable;

final class BackupRunRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM backup_runs ORDER BY id DESC LIMIT 50');
    }

    public function start(): int
    {
        return (int) $this->db->insert(
            'INSERT INTO backup_runs (status, started_at) VALUES (?, ?)',
            ['running', (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    public function complete(int $id, string $filePath, int $sizeBytes): void
    {
        $this->db->update(
            'UPDATE backup_runs SET status = ?, file_path = ?, size_bytes = ?, finished_at = ? WHERE id = ?',
            ['success', $filePath, $sizeBytes, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function fail(int $id, string $error): void
    {
        $this->db->update(
            'UPDATE backup_runs SET status = ?, error = ?, finished_at = ? WHERE id = ?',
            ['failed', $error, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }
}
