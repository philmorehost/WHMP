<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

use CodeVault\Database;
use DateTimeImmutable;

final class KbArticleRepository
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
            SELECT a.*, c.name AS category_name
            FROM kb_articles a
            JOIN kb_categories c ON c.id = a.category_id
            ORDER BY a.title
            SQL
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forCategory(int $categoryId): array
    {
        return $this->db->select('SELECT * FROM kb_articles WHERE category_id = ? ORDER BY title', [$categoryId]);
    }

    /**
     * All articles in one query, grouped by category_id in PHP — avoids
     * the N+1 of calling forCategory() once per category when rendering
     * the full public KB index.
     *
     * @return array<int, array<int, array<string, mixed>>> category_id => articles
     */
    public function allGroupedByCategory(): array
    {
        $rows = $this->db->select('SELECT * FROM kb_articles ORDER BY category_id, title');
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['category_id']][] = $row;
        }

        return $grouped;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            <<<'SQL'
            SELECT a.*, c.name AS category_name
            FROM kb_articles a
            JOIN kb_categories c ON c.id = a.category_id
            WHERE a.id = ?
            SQL,
            [$id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $query): array
    {
        $like = '%' . $query . '%';

        return $this->db->select(
            <<<'SQL'
            SELECT a.*, c.name AS category_name
            FROM kb_articles a
            JOIN kb_categories c ON c.id = a.category_id
            WHERE a.title LIKE ? OR a.body LIKE ?
            ORDER BY a.views DESC
            SQL,
            [$like, $like]
        );
    }

    public function create(int $categoryId, string $title, string $body): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO kb_articles (category_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$categoryId, $title, $body, $now, $now]
        );
    }

    public function update(int $id, int $categoryId, string $title, string $body): void
    {
        $this->db->update(
            'UPDATE kb_articles SET category_id = ?, title = ?, body = ?, updated_at = ? WHERE id = ?',
            [$categoryId, $title, $body, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM kb_articles WHERE id = ?', [$id]);
    }

    public function incrementViews(int $id): void
    {
        $this->db->update('UPDATE kb_articles SET views = views + 1 WHERE id = ?', [$id]);
    }

    public function markHelpful(int $id): void
    {
        $this->db->update('UPDATE kb_articles SET helpful_count = helpful_count + 1 WHERE id = ?', [$id]);
    }

    public function markUnhelpful(int $id): void
    {
        $this->db->update('UPDATE kb_articles SET unhelpful_count = unhelpful_count + 1 WHERE id = ?', [$id]);
    }
}
