<?php

declare(strict_types=1);

namespace CodeVault\Gdpr;

use CodeVault\Database;
use DateTimeImmutable;

final class GdprRequestRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function create(int $clientId, string $type): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO gdpr_requests (client_id, type, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$clientId, $type, 'pending', $now, $now]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM gdpr_requests WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> requests joined with the client's email, newest first */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT g.*, c.email AS client_email
            FROM gdpr_requests g
            JOIN clients c ON c.id = g.client_id
            ORDER BY g.id DESC
            SQL
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select('SELECT * FROM gdpr_requests WHERE client_id = ? ORDER BY id DESC', [$clientId]);
    }

    public function markCompleted(int $id, int $adminId, ?string $exportData, ?string $notes): void
    {
        $this->db->update(
            'UPDATE gdpr_requests SET status = ?, export_data = ?, admin_notes = ?, processed_by_admin_id = ?, processed_at = ?, updated_at = ? WHERE id = ?',
            ['completed', $exportData, $notes, $adminId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markRejected(int $id, int $adminId, ?string $notes): void
    {
        $this->db->update(
            'UPDATE gdpr_requests SET status = ?, admin_notes = ?, processed_by_admin_id = ?, processed_at = ?, updated_at = ? WHERE id = ?',
            ['rejected', $notes, $adminId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }
}
