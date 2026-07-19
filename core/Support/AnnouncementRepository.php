<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Database;
use DateTimeImmutable;

final class AnnouncementRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM announcements ORDER BY published_at DESC');
    }

    /** @return array<int, array<string, mixed>> announcements visible to the public right now */
    public function published(int $limit = 20): array
    {
        return $this->db->select(
            'SELECT * FROM announcements WHERE published_at <= ? ORDER BY published_at DESC LIMIT ' . max(1, min(100, $limit)),
            [(new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM announcements WHERE id = ?', [$id]);
    }

    public function create(string $title, string $body, string $publishedAt): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO announcements (title, body, published_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$title, $body, $publishedAt, $now, $now]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM announcements WHERE id = ?', [$id]);
    }
}
