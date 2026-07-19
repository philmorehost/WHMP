<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Database;
use DateTimeImmutable;

final class ImportRunRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * `entity_type` has existed on this table since R16 (default 'clients',
     * anticipating exactly this R29 extension) but nothing ever set it
     * explicitly until now.
     *
     * @param array<int, array{row: int, reason: string}> $errors
     */
    public function create(int $adminId, string $entityType, string $filename, int $totalRows, int $importedCount, int $skippedCount, array $errors): int
    {
        return (int) $this->db->insert(
            'INSERT INTO import_runs (admin_id, entity_type, filename, total_rows, imported_count, skipped_count, errors, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$adminId, $entityType, $filename, $totalRows, $importedCount, $skippedCount, json_encode($errors), (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    /** @return array<int, array<string, mixed>> most recent runs of every type, newest first */
    public function recent(int $limit = 20): array
    {
        return $this->db->select('SELECT * FROM import_runs ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
    }

    /** @return array<int, array<string, mixed>> most recent runs of one type, newest first */
    public function recentByType(string $entityType, int $limit = 20): array
    {
        return $this->db->select(
            'SELECT * FROM import_runs WHERE entity_type = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$entityType]
        );
    }
}
