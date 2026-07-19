<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

use CodeVault\Database;
use DateTimeImmutable;

final class KbCategoryRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM kb_categories ORDER BY sort_order, name');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM kb_categories WHERE id = ?', [$id]);
    }

    public function create(string $name, int $sortOrder = 0): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO kb_categories (name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, $sortOrder, $now, $now]
        );
    }

    public function update(int $id, string $name, int $sortOrder): void
    {
        $this->db->update(
            'UPDATE kb_categories SET name = ?, sort_order = ?, updated_at = ? WHERE id = ?',
            [$name, $sortOrder, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** @return bool true if deleted, false if the category still has articles */
    public function delete(int $id): bool
    {
        $count = (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM kb_articles WHERE category_id = ?', [$id])['c'] ?? 0);

        if ($count > 0) {
            return false;
        }

        $this->db->delete('DELETE FROM kb_categories WHERE id = ?', [$id]);

        return true;
    }
}
