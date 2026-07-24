<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;

final class CancellationRequestRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $serviceId, int $clientId, string $type, string $reason, ?string $cancelDate = null): int
    {
        return (int) $this->db->insert(
            'INSERT INTO cancellation_requests (service_id, client_id, cancellation_type, cancel_date, reason, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$serviceId, $clientId, $type, $reason, $cancelDate]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cancellation_requests WHERE id = ?',
            [$id]
        );
    }

    public function findPending(): array
    {
        return $this->db->select(
            'SELECT cr.*, c.first_name, c.last_name, c.email, s.product_name, s.server_id
             FROM cancellation_requests cr
             JOIN clients c ON cr.client_id = c.id
             JOIN services s ON cr.service_id = s.id
             WHERE cr.status = ?
             ORDER BY cr.created_at DESC',
            ['pending']
        );
    }

    public function findByStatus(string $status): array
    {
        return $this->db->select(
            'SELECT cr.*, c.first_name, c.last_name, c.email, s.product_name
             FROM cancellation_requests cr
             JOIN clients c ON cr.client_id = c.id
             JOIN services s ON cr.service_id = s.id
             WHERE cr.status = ?
             ORDER BY cr.created_at DESC',
            [$status]
        );
    }

    public function findByService(int $serviceId): array
    {
        return $this->db->select(
            'SELECT * FROM cancellation_requests WHERE service_id = ? ORDER BY created_at DESC',
            [$serviceId]
        );
    }

    public function countPending(): int
    {
        $result = $this->db->selectOne(
            'SELECT COUNT(*) as count FROM cancellation_requests WHERE status = ?',
            ['pending']
        );
        return (int) ($result['count'] ?? 0);
    }

    public function approve(int $id, int $adminId, ?string $notes = null): void
    {
        $this->db->update(
            'UPDATE cancellation_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE id = ?',
            ['approved', $adminId, $notes, $id]
        );
    }

    public function reject(int $id, int $adminId, string $notes): void
    {
        $this->db->update(
            'UPDATE cancellation_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE id = ?',
            ['rejected', $adminId, $notes, $id]
        );
    }

    public function markCompleted(int $id): void
    {
        $this->db->update(
            'UPDATE cancellation_requests SET status = ?, completed_at = NOW() WHERE id = ?',
            ['completed', $id]
        );
    }

    public function findDueCancellations(): array
    {
        return $this->db->select(
            'SELECT cr.*, s.server_id, p.slug as provisioning_module
             FROM cancellation_requests cr
             JOIN services s ON cr.service_id = s.id
             JOIN products p ON s.product_id = p.id
             WHERE cr.status = ? AND cr.cancellation_type = ? AND cr.cancel_date <= CURDATE()
             ORDER BY cr.cancel_date ASC',
            ['approved', 'due_date']
        );
    }
}
