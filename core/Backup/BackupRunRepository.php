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

    /**
     * Every run still marked "running". A live backup flips its row to
     * success/failed when it finishes, so a row stuck in running is either a
     * process executing right now or a leftover of one that was killed
     * mid-dump — the Terminate button cleans out both.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allRunning(): array
    {
        return $this->db->select("SELECT * FROM backup_runs WHERE status = 'running' ORDER BY id DESC");
    }

    /**
     * Deletes exact rows by id (not by status) — the id list is captured from
     * a prior allRunning() read, so a run that legitimately starts in between
     * cannot have its fresh row swept up by accident.
     *
     * @param array<int, int> $ids
     */
    public function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return 0;
        }

        return $this->db->delete('DELETE FROM backup_runs WHERE id IN (' . implode(',', $ids) . ')');
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

    /**
     * Deletes log rows older than the cutoff — these are just run history
     * (status/timing/file path), not the backup files themselves, which
     * BackupService::pruneOldBackups() already keeps bounded by count. With
     * a backup running as often as hourly, this table otherwise grows
     * forever with no way to trim it.
     */
    public function deleteOlderThan(string $beforeDateTime): int
    {
        return $this->db->delete('DELETE FROM backup_runs WHERE started_at < ?', [$beforeDateTime]);
    }
}
