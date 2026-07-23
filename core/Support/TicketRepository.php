<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Database;
use DateTimeImmutable;

final class TicketRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            <<<'SQL'
            SELECT t.*, d.name AS department_name
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            WHERE t.id = ?
            SQL,
            [$id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT t.*, d.name AS department_name
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            WHERE t.client_id = ?
            ORDER BY t.id DESC
            SQL,
            [$clientId]
        );
    }

    /**
     * @param array{status?: string, departmentId?: int, assignedAdminId?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function all(array $filters = []): array
    {
        $where = [];
        $bindings = [];

        if (isset($filters['status'])) {
            $where[] = 't.status = ?';
            $bindings[] = $filters['status'];
        }

        if (isset($filters['departmentId'])) {
            $where[] = 't.department_id = ?';
            $bindings[] = $filters['departmentId'];
        }

        if (isset($filters['assignedAdminId'])) {
            $where[] = 't.assigned_admin_id = ?';
            $bindings[] = $filters['assignedAdminId'];
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        return $this->db->select(
            <<<SQL
            SELECT t.*, d.name AS department_name, a.display_name AS assigned_admin_name
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            LEFT JOIN admins a ON a.id = t.assigned_admin_id
            {$whereSql}
            ORDER BY 
              CASE t.status
                WHEN 'open' THEN 1
                WHEN 'customer-reply' THEN 2
                WHEN 'answered' THEN 3
                ELSE 4
              END ASC, 
              t.priority = 'high' DESC, 
              t.updated_at DESC
            SQL,
            $bindings
        );
    }

    /**
     * @param array{status?: string, departmentId?: int, assignedAdminId?: int} $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (isset($filters['status'])) {
            $where[] = 't.status = ?';
            $bindings[] = $filters['status'];
        }

        if (isset($filters['departmentId'])) {
            $where[] = 't.department_id = ?';
            $bindings[] = $filters['departmentId'];
        }

        if (isset($filters['assignedAdminId'])) {
            $where[] = 't.assigned_admin_id = ?';
            $bindings[] = $filters['assignedAdminId'];
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM tickets t {$whereSql}", $bindings);
        $total = (int) ($totalRow['c'] ?? 0);

        $data = $this->db->select(
            <<<SQL
            SELECT t.*, d.name AS department_name, a.display_name AS assigned_admin_name
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            LEFT JOIN admins a ON a.id = t.assigned_admin_id
            {$whereSql}
            ORDER BY 
              CASE t.status
                WHEN 'open' THEN 1
                WHEN 'customer-reply' THEN 2
                WHEN 'answered' THEN 3
                ELSE 4
              END ASC, 
              t.priority = 'high' DESC, 
              t.updated_at DESC
            LIMIT {$perPage} OFFSET {$offset}
            SQL,
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /** Dashboard tile (R17) — everything not closed, not the full joined row set. */
    public function countOpen(): int
    {
        $row = $this->db->selectOne("SELECT COUNT(*) AS c FROM tickets WHERE status != 'closed'");

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Tickets awaiting an admin reply for longer than N minutes (blueprint
     * §4.4 "escalation rules, SLA priority").
     *
     * @return array<int, array<string, mixed>>
     */
    public function awaitingReplyLongerThan(int $minutes): array
    {
        $cutoff = (new DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s');

        return $this->db->select(
            "SELECT * FROM tickets WHERE status IN ('open', 'customer-reply') AND last_reply_by = 'client' AND last_reply_at <= ?",
            [$cutoff]
        );
    }

    /**
     * Answered tickets with no client reply for longer than N days
     * (blueprint §4.4 "auto-close on inactivity").
     *
     * @return array<int, array<string, mixed>>
     */
    public function inactiveLongerThan(int $days): array
    {
        $cutoff = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

        return $this->db->select(
            "SELECT * FROM tickets WHERE status = 'answered' AND updated_at <= ?",
            [$cutoff]
        );
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO tickets (client_id, email, department_id, subject, status, priority, last_reply_at, last_reply_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['client_id'] ?? null,
                $fields['email'],
                $fields['department_id'],
                $fields['subject'],
                $fields['status'] ?? 'open',
                $fields['priority'] ?? 'medium',
                $now,
                'client',
                $now,
                $now,
            ]
        );
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->update(
            'UPDATE tickets SET status = ?, updated_at = ? WHERE id = ?',
            [$status, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setPriority(int $id, string $priority): void
    {
        $this->db->update(
            'UPDATE tickets SET priority = ?, updated_at = ? WHERE id = ?',
            [$priority, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function assign(int $id, ?int $adminId): void
    {
        $this->db->update(
            'UPDATE tickets SET assigned_admin_id = ?, updated_at = ? WHERE id = ?',
            [$adminId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setDepartment(int $id, int $departmentId): void
    {
        $this->db->update(
            'UPDATE tickets SET department_id = ?, updated_at = ? WHERE id = ?',
            [$departmentId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function recordReply(int $id, string $authorType, string $newStatus): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update(
            'UPDATE tickets SET status = ?, last_reply_at = ?, last_reply_by = ?, updated_at = ? WHERE id = ?',
            [$newStatus, $now, $authorType, $now, $id]
        );
    }

    public function setRating(int $id, int $rating): void
    {
        $this->db->update(
            'UPDATE tickets SET satisfaction_rating = ?, updated_at = ? WHERE id = ?',
            [$rating, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }
}
