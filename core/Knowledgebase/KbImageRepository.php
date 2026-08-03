<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

use CodeVault\Database;
use DateTimeImmutable;

final class KbImageRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function forArticle(int $articleId): array
    {
        return $this->db->select(
            'SELECT * FROM kb_article_images WHERE article_id = ? ORDER BY sort_order ASC, id ASC',
            [$articleId]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM kb_article_images WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        return (int) $this->db->insert(
            'INSERT INTO kb_article_images
                (article_id, source, original_name, stored_name, svg_content, mime_type, size_bytes, caption, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['article_id'],
                $fields['source'],
                $fields['original_name'] ?? null,
                $fields['stored_name'] ?? null,
                $fields['svg_content'] ?? null,
                $fields['mime_type'] ?? null,
                $fields['size_bytes'] ?? null,
                $fields['caption'] ?? null,
                $fields['sort_order'] ?? $this->nextSortOrder((int) $fields['article_id']),
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM kb_article_images WHERE id = ?', [$id]);
    }

    public function nextSortOrder(int $articleId): int
    {
        $row = $this->db->selectOne('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM kb_article_images WHERE article_id = ?', [$articleId]);

        return (int) ($row['next_order'] ?? 0);
    }
}
