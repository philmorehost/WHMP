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
            SELECT t.*, d.name AS department_name, c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            LEFT JOIN clients c ON c.id = t.client_id
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
            SELECT t.*, d.name AS department_name, c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            LEFT JOIN clients c ON c.id = t.client_id
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
            SELECT t.*, d.name AS department_name, a.display_name AS assigned_admin_name, c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            LEFT JOIN admins a ON a.id = t.assigned_admin_id
            LEFT JOIN clients c ON c.id = t.client_id
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
     * @param array<string, string> $columnFilters sanitised `filters[]` bag (see Table\TableFilters)
     * @param array{column: string, dir: string}|null $sort
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20, array $columnFilters = [], ?array $sort = null): array
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

        [$columnWhere, $columnBindings] = \CodeVault\Table\TableFilters::where($columnFilters, [
            'id'         => ['t.id', 'number'],
            'client'     => [['c.first_name', 'c.last_name', 'c.email'], 'like'],
            'subject'    => ['t.subject', 'like'],
            'department' => ['d.name', 'like'],
            'priority'   => ['t.priority', 'eq'],
            'status'     => ['t.status', 'eq'],
        ]);

        if ($columnWhere !== '') {
            $where[] = $columnWhere;
            $bindings = array_merge($bindings, $columnBindings);
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $sortable = [
            'id'         => 't.id',
            'client'     => 'c.last_name',
            'subject'    => 't.subject',
            'department' => 'd.name',
            'priority'   => 't.priority',
            'status'     => 't.status',
        ];
        $orderBy = \CodeVault\Table\TableFilters::orderBy($sortable, $sort);
        if ($orderBy === '') {
            $orderBy = <<<'SQL'
            ORDER BY 
              CASE t.status
                WHEN 'open' THEN 1
                WHEN 'customer-reply' THEN 2
                WHEN 'answered' THEN 3
                ELSE 4
              END ASC, 
              t.priority = 'high' DESC, 
              t.updated_at DESC
            SQL;
        }

        $totalRow = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM tickets t JOIN departments d ON d.id = t.department_id LEFT JOIN clients c ON c.id = t.client_id {$whereSql}",
            $bindings
        );
        $total = (int) ($totalRow['c'] ?? 0);

        $data = $this->db->select(
            <<<SQL
            SELECT t.*, d.name AS department_name, a.display_name AS assigned_admin_name, c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            LEFT JOIN admins a ON a.id = t.assigned_admin_id
            LEFT JOIN clients c ON c.id = t.client_id
            {$whereSql}
            {$orderBy}
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

    /**
     * Marks $id as absorbed into $targetId and closes it — called after its
     * replies/attachments have already been moved onto the target (see
     * TicketService::merge()), never on its own.
     */
    public function setMergedInto(int $id, int $targetId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update(
            "UPDATE tickets SET merged_into_id = ?, status = 'closed', updated_at = ? WHERE id = ?",
            [$targetId, $now, $id]
        );
    }

    /**
     * Of the given tickets, the ids that aren't closed yet.
     *
     * Lets a bulk close skip tickets already in that state, so the count
     * reported back reflects what actually changed and the TicketClose hook
     * doesn't re-fire for a ticket that was closed weeks ago.
     *
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    public function openIdsAmong(array $ids): array
    {
        $ids = self::normaliseIds($ids);

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return array_map(
            'intval',
            array_column(
                $this->db->select("SELECT id FROM tickets WHERE status <> 'closed' AND id IN ({$placeholders})", $ids),
                'id'
            )
        );
    }

    /**
     * Stored filenames of every attachment on these tickets.
     *
     * Must be called BEFORE deleteMany(): the ticket_attachments rows cascade
     * away with the ticket, so once it's gone there is no record of which
     * files on disk belonged to it.
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    public function attachmentFilesFor(array $ids): array
    {
        $ids = self::normaliseIds($ids);

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return array_column(
            $this->db->select("SELECT stored_name FROM ticket_attachments WHERE ticket_id IN ({$placeholders})", $ids),
            'stored_name'
        );
    }

    /**
     * Permanently deletes tickets, with their replies and attachment records.
     *
     * Replies and attachment rows are removed by the foreign keys' ON DELETE
     * CASCADE, so they aren't deleted explicitly here — but the files on disk
     * are not covered by that, which is why callers pair this with
     * attachmentFilesFor() and TicketAttachmentService::deleteFiles().
     *
     * @param array<int, int> $ids
     * @return int tickets deleted
     */
    public function deleteMany(array $ids): int
    {
        $ids = self::normaliseIds($ids);

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return $this->db->delete("DELETE FROM tickets WHERE id IN ({$placeholders})", $ids);
    }

    /**
     * The clean-up scopes offered when emptying a department, mapped to the
     * SQL that selects them.
     *
     * Age is measured from the last reply, falling back to when the ticket was
     * opened — deliberately NOT updated_at, which gets bumped by unrelated
     * admin actions (moving a ticket between departments, for one) and would
     * make ancient tickets look recent.
     *
     * @var array<string, array{label: string, sql: string}>
     */
    private const PURGE_SCOPES = [
        'closed_365' => ['label' => 'Closed & untouched for 1 year', 'sql' => "t.status = 'closed' AND COALESCE(t.last_reply_at, t.created_at) < (NOW() - INTERVAL 365 DAY)"],
        'closed_180' => ['label' => 'Closed & untouched for 6 months', 'sql' => "t.status = 'closed' AND COALESCE(t.last_reply_at, t.created_at) < (NOW() - INTERVAL 180 DAY)"],
        'closed_90' => ['label' => 'Closed & untouched for 90 days', 'sql' => "t.status = 'closed' AND COALESCE(t.last_reply_at, t.created_at) < (NOW() - INTERVAL 90 DAY)"],
        'closed' => ['label' => 'All closed tickets', 'sql' => "t.status = 'closed'"],
        'older_365' => ['label' => 'Anything untouched for 1 year', 'sql' => "COALESCE(t.last_reply_at, t.created_at) < (NOW() - INTERVAL 365 DAY)"],
        'all' => ['label' => 'EVERY ticket in this department', 'sql' => '1 = 1'],
    ];

    /** @return array<string, string> scope key => human label */
    public static function purgeScopes(): array
    {
        return array_map(static fn (array $s): string => $s['label'], self::PURGE_SCOPES);
    }

    public static function isPurgeScope(string $scope): bool
    {
        return isset(self::PURGE_SCOPES[$scope]);
    }

    /** How many tickets a given clean-up would remove. */
    public function countInDepartmentScope(int $departmentId, string $scope): int
    {
        if (!self::isPurgeScope($scope)) {
            return 0;
        }

        $where = self::PURGE_SCOPES[$scope]['sql'];

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM tickets t WHERE t.department_id = ? AND {$where}",
            [$departmentId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * One batch of ticket ids to remove.
     *
     * Purges run in batches rather than one enormous DELETE so a department
     * holding thousands of tickets can't blow the request timeout or lock the
     * table for the length of a full cascade.
     *
     * @return array<int, int>
     */
    public function idsInDepartmentScope(int $departmentId, string $scope, int $limit): array
    {
        if (!self::isPurgeScope($scope) || $limit < 1) {
            return [];
        }

        $where = self::PURGE_SCOPES[$scope]['sql'];
        $limit = min(1000, $limit);

        return array_map('intval', array_column($this->db->select(
            "SELECT t.id FROM tickets t WHERE t.department_id = ? AND {$where} ORDER BY t.id LIMIT {$limit}",
            [$departmentId]
        ), 'id'));
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    private static function normaliseIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
    }
}
