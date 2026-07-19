<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Database;
use DateTimeImmutable;

final class NetworkIssueRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM network_issues ORDER BY started_at DESC');
    }

    /** @return array<int, array<string, mixed>> unresolved issues, for the status page banner */
    public function active(): array
    {
        return $this->db->select("SELECT * FROM network_issues WHERE status != 'resolved' ORDER BY started_at DESC");
    }

    /** @return array<int, array<string, mixed>> */
    public function recentlyResolved(int $limit = 10): array
    {
        return $this->db->select(
            "SELECT * FROM network_issues WHERE status = 'resolved' ORDER BY resolved_at DESC LIMIT " . max(1, min(50, $limit))
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM network_issues WHERE id = ?', [$id]);
    }

    public function create(string $title, string $message, string $status): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO network_issues (title, message, status, started_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$title, $message, $status, $now, $now, $now]
        );
    }

    public function updateStatus(int $id, string $status, ?string $message = null): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $resolvedAt = $status === 'resolved' ? $now : null;

        if ($message !== null) {
            $this->db->update(
                'UPDATE network_issues SET status = ?, message = ?, resolved_at = ?, updated_at = ? WHERE id = ?',
                [$status, $message, $resolvedAt, $now, $id]
            );

            return;
        }

        $this->db->update(
            'UPDATE network_issues SET status = ?, resolved_at = ?, updated_at = ? WHERE id = ?',
            [$status, $resolvedAt, $now, $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM network_issues WHERE id = ?', [$id]);
    }
}
