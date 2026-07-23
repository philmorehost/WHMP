<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

final class CancellationRequestRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function createRequest(int $serviceId, string $type, ?string $reason): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        return (int) $this->db->insert(
            'INSERT INTO cancellation_requests (service_id, type, reason, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$serviceId, $type, $reason, 'pending', $now, $now]
        );
    }

    /** @return array<string, mixed>|null */
    public function findPendingForService(int $serviceId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cancellation_requests WHERE service_id = ? AND status = ?',
            [$serviceId, 'pending']
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function allPending(): array
    {
        return $this->db->select(
            'SELECT cr.*, s.product_name, s.domain, s.hostname, s.next_due_date, c.first_name, c.last_name, c.email ' .
            'FROM cancellation_requests cr ' .
            'JOIN services s ON s.id = cr.service_id ' .
            'JOIN clients c ON c.id = s.client_id ' .
            'WHERE cr.status = ? ' .
            'ORDER BY cr.created_at ASC',
            ['pending']
        );
    }

    public function markProcessed(int $id): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update(
            'UPDATE cancellation_requests SET status = ?, updated_at = ? WHERE id = ?',
            ['processed', $now, $id]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT cr.*, s.product_name, s.domain, s.hostname, s.next_due_date ' .
            'FROM cancellation_requests cr ' .
            'JOIN services s ON s.id = cr.service_id ' .
            'WHERE cr.id = ?',
            [$id]
        );
    }
}
