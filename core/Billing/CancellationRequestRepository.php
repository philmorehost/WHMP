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
        // Normalise the caller's mode into the stored enum. `cancellation_type`
        // is ('immediate','due_date'); 'end_of_period' is an end-of-term
        // request and is stored as a due_date cancellation at the end of the
        // current billing period. The caller's raw mode is preserved in the
        // separate `type` column.
        //
        // (Also fixes a long-standing bind-order bug: the VALUES list used to
        // be [$type, $reason, $cancelDate] against columns (cancellation_type,
        // cancel_date, reason), so the reason landed in cancel_date and the
        // date landed in reason.)
        $cancellationType = $type === 'immediate' ? 'immediate' : 'due_date';
        // The separate `type` column is enum ('immediate','end_of_period') —
        // normalise the caller's 'due_date' into that vocabulary too.
        $typeColumn = $type === 'immediate' ? 'immediate' : 'end_of_period';

        return (int) $this->db->insert(
            'INSERT INTO cancellation_requests (service_id, client_id, cancellation_type, type, cancel_date, reason, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$serviceId, $clientId, $cancellationType, $typeColumn, $cancelDate, $reason]
        );
    }

    public function createRequest(int $serviceId, string $type, string $reason, ?int $clientId = null, ?string $cancelDate = null): int
    {
        if ($clientId === null || $clientId <= 0) {
            $service = $this->db->selectOne('SELECT client_id FROM services WHERE id = ?', [$serviceId]);
            $clientId = $service ? (int) $service['client_id'] : 0;
        }

        return $this->create($serviceId, $clientId, $type, $reason, $cancelDate);
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
            'SELECT cr.*, c.first_name, c.last_name, c.email, s.product_name,
                    s.status AS service_status, s.domain, s.hostname, s.next_due_date,
                    a.username AS reviewed_by_name
             FROM cancellation_requests cr
             JOIN clients c ON cr.client_id = c.id
             JOIN services s ON cr.service_id = s.id
             LEFT JOIN admins a ON a.id = cr.reviewed_by
             WHERE cr.status = ?
             ORDER BY cr.created_at DESC',
            [$status]
        );
    }

    /** @return array{pending: int, approved: int, rejected: int, completed: int} */
    public function counts(): array
    {
        $rows = $this->db->select('SELECT status, COUNT(*) AS count FROM cancellation_requests GROUP BY status');
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'completed' => 0];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['count'];
            }
        }

        return $counts;
    }

    public function findByService(int $serviceId): array
    {
        return $this->db->select(
            'SELECT * FROM cancellation_requests WHERE service_id = ? ORDER BY created_at DESC',
            [$serviceId]
        );
    }

    public function findPendingForService(int $serviceId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cancellation_requests WHERE service_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1',
            [$serviceId, 'pending']
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

    /**
     * Approved cancellations whose scheduled date has arrived.
     *
     * This used to select `p.slug AS provisioning_module` over a join to
     * `products`. Products have never had a `slug` column, so the query was a
     * guaranteed error — it just never surfaced, because CancellationCronJob
     * couldn't be loaded at all until its class declaration was fixed. Nor was
     * a slug the right source: a service's provisioning module comes from its
     * *server* (`servers.module_slug`, which is what ProvisioningService
     * resolves against), not from its product. No caller ever read the alias,
     * so the column and its join are gone rather than replaced — and dropping
     * the inner join to `products` also stops due cancellations being silently
     * skipped for services with no matching product row, such as domains.
     */
    public function findDueCancellations(): array
    {
        return $this->db->select(
            'SELECT cr.*, s.server_id
             FROM cancellation_requests cr
             JOIN services s ON cr.service_id = s.id
             WHERE cr.status = ? AND cr.cancellation_type = ? AND cr.cancel_date <= CURDATE()
             ORDER BY cr.cancel_date ASC',
            ['approved', 'due_date']
        );
    }
}
