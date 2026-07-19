<?php

declare(strict_types=1);

namespace CodeVault\Downloads;

use CodeVault\Database;
use DateTimeImmutable;

final class DownloadRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT d.*, c.name AS category_name
            FROM downloads d
            JOIN download_categories c ON c.id = d.category_id
            ORDER BY d.name
            SQL
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forCategory(int $categoryId): array
    {
        return $this->db->select('SELECT * FROM downloads WHERE category_id = ? ORDER BY name', [$categoryId]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM downloads WHERE id = ?', [$id]);
    }

    public function create(int $categoryId, string $name, ?string $description, string $filePath, ?int $fileSize): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO downloads (category_id, name, description, file_path, file_size, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$categoryId, $name, $description, $filePath, $fileSize, $now, $now]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM downloads WHERE id = ?', [$id]);
    }

    public function incrementDownloadCount(int $id): void
    {
        $this->db->update('UPDATE downloads SET download_count = download_count + 1 WHERE id = ?', [$id]);
    }
}
