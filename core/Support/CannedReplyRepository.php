<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Database;
use DateTimeImmutable;

final class CannedReplyRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM canned_replies ORDER BY title');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM canned_replies WHERE id = ?', [$id]);
    }

    public function create(string $title, string $body): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO canned_replies (title, body, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$title, $body, $now, $now]
        );
    }

    public function update(int $id, string $title, string $body): void
    {
        $this->db->update(
            'UPDATE canned_replies SET title = ?, body = ?, updated_at = ? WHERE id = ?',
            [$title, $body, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM canned_replies WHERE id = ?', [$id]);
    }
}
