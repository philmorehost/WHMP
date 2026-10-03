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
            SELECT t.*, d.name AS department_name, c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email,
                   s.product_name AS service_product_name, s.domain AS service_domain, dm.domain_name AS related_domain_name
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            LEFT JOIN clients c ON c.id = t.client_id
            LEFT JOIN services s ON s.id = t.service_id
            LEFT JOIN domains dm ON dm.id = t.domain_id
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

        // STRICT RESELLER ISOLATION: the admin's list holds the platform's own
        // customers' records only. A store's customers' records are reached through
        // that reseller's page (/admin/resellers/{id}/customers), never listed here.
        $where = ['t.reseller_id IS NULL'];
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
        $row = $this->db->selectOne("SELECT COUNT(*) AS c FROM tickets WHERE status != 'closed' AND reseller_id IS NULL"); // the platform desk only — store tickets are the reseller's

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Number of non-closed tickets raised from a sender email (case-
     * insensitive). The mail-piping flood guard uses this to stop one
     * address — a bounce loop or an abuser — from opening unbounded
     * tickets. Emails are stored lowercased (see create()), so the plain
     * indexed equality below hits idx_tickets_email instead of wrapping
     * the column in LOWER(), which would force a full scan on large tables.
     */
    public function countOpenByEmail(string $email): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM tickets WHERE status != 'closed' AND email = ?",
            [strtolower(trim($email))]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Number of non-closed tickets for a client account. Guards the client
     * ticket form so one account can't flood the admin queue.
     */
    public function countOpenByClient(int $clientId): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM tickets WHERE status != 'closed' AND client_id = ?",
            [$clientId]
        );

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

    /**
     * `service_id`/`domain_id` name the item the ticket is about. Both are
     * optional — a general enquiry, a piped-in email and every ticket opened
     * by a non-portal flow leave them NULL — so callers that don't know about
     * them keep working unchanged.
     *
     * `reseller_id` is DERIVED, not passed: the ticket belongs to whichever store
     * owns the client, which is the single attribution rule this platform already
     * uses (`clients.reseller_id`). Deriving it here rather than taking it as an
     * argument is what makes every creation path — the portal form, an
     * admin-created ticket, mail piping — stamp it without remembering to.
     *
     * The subselect joins `resellers` rather than reading `clients.reseller_id`
     * directly, so a stale value on the client cannot violate the new foreign key;
     * it simply resolves to NULL and the ticket lands on the platform's desk.
     *
     * @param array<string, mixed> $fields
     */
    public function create(array $fields): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $clientId = isset($fields['client_id']) && (int) $fields['client_id'] > 0 ? (int) $fields['client_id'] : null;
        $explicit = isset($fields['reseller_id']) && (int) $fields['reseller_id'] > 0 ? (int) $fields['reseller_id'] : null;

        return (int) $this->db->insert(
            // Counting note, because this bit me: `reseller_id` is ONE value slot
            // even though its COALESCE contains TWO placeholders. So the value list
            // below is one slot fewer than the placeholder count, and the 11 `?`
            // after the COALESCE are the 11 columns that follow reseller_id.
            'INSERT INTO tickets (client_id, reseller_id, email, department_id, service_id, domain_id, subject, status, priority, last_reply_at, last_reply_by, created_at, updated_at)
             VALUES (?, COALESCE(?, (SELECT r.id FROM clients c JOIN resellers r ON r.id = c.reseller_id WHERE c.id = ?)), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $clientId,
                // A caller that already knows the store wins — a message piped to a
                // store's own support address has no client to look up.
                $explicit,
                $clientId ?? 0,
                // Emails are stored lowercased so sender lookups (the mail-
                // piping flood guard) can use the email index.
                strtolower(trim((string) ($fields['email'] ?? ''))),
                $fields['department_id'],
                isset($fields['service_id']) && (int) $fields['service_id'] > 0 ? (int) $fields['service_id'] : null,
                isset($fields['domain_id']) && (int) $fields['domain_id'] > 0 ? (int) $fields['domain_id'] : null,
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

    /**
     * One store's tickets.
     *
     * Ordered exactly like the platform's queue (open, then customer-reply, then
     * answered, then closed) rather than by a second convention of our own — a
     * reseller and an admin looking at the same ticket should not disagree about
     * which one needs attention.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forReseller(int $resellerId, int $limit = 200): array
    {
        return $this->db->select(
            "SELECT t.*, d.name AS department_name,
                    c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email
             FROM tickets t
             JOIN departments d ON d.id = t.department_id
             LEFT JOIN clients c ON c.id = t.client_id
             WHERE t.reseller_id = ?
             ORDER BY CASE t.status
                        WHEN 'open' THEN 1
                        WHEN 'customer-reply' THEN 2
                        WHEN 'answered' THEN 3
                        ELSE 4
                      END ASC,
                      t.updated_at DESC
             LIMIT " . max(1, $limit),
            [$resellerId]
        );
    }

    /**
     * One ticket, but only if it belongs to that store.
     *
     * The ownership test is part of the QUERY, not an `if` a caller has to
     * remember: a store's portal takes the ticket id from the URL, so there is no
     * shape of request that can read another store's conversation.
     *
     * @return array<string, mixed>|null
     */
    public function forResellerTicket(int $ticketId, int $resellerId): ?array
    {
        return $this->db->selectOne(
            'SELECT t.*, d.name AS department_name,
                    c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email
             FROM tickets t
             JOIN departments d ON d.id = t.department_id
             LEFT JOIN clients c ON c.id = t.client_id
             WHERE t.id = ? AND t.reseller_id = ?',
            [$ticketId, $resellerId]
        );
    }

    /**
     * Pass a ticket up to the platform. FALSE when it is not this store's ticket
     * or is already escalated, so a double-click cannot overwrite the first note.
     */
    public function escalateForReseller(int $ticketId, int $resellerId, ?string $note): bool
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->db->update(
            'UPDATE tickets SET escalated_at = ?, escalated_note = ?, updated_at = ?
             WHERE id = ? AND reseller_id = ? AND escalated_at IS NULL',
            [$now, $note, $now, $ticketId, $resellerId]
        ) > 0;
    }

    /** The store takes it back — it resolved the ticket itself after all. */
    public function withdrawEscalationForReseller(int $ticketId, int $resellerId): bool
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->db->update(
            'UPDATE tickets SET escalated_at = NULL, escalated_note = NULL, updated_at = ?
             WHERE id = ? AND reseller_id = ? AND escalated_at IS NOT NULL',
            [$now, $ticketId, $resellerId]
        ) > 0;
    }

    /**
     * Drop the escalation without going through a store — used when the platform
     * answers, which is the thing the escalation was asking for.
     */
    public function clearEscalation(int $ticketId): void
    {
        $this->db->update(
            'UPDATE tickets SET escalated_at = NULL, escalated_note = NULL, updated_at = ?
             WHERE id = ? AND escalated_at IS NOT NULL',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $ticketId]
        );
    }

    /**
     * What a store has handed up and nobody has answered — the platform's queue.
     *
     * Oldest first, because this is a work list and the one waiting longest is the
     * one costing the most.
     *
     * @return array<int, array<string, mixed>>
     */
    public function escalatedToPlatform(int $limit = 100): array
    {
        return $this->db->select(
            "SELECT t.*, d.name AS department_name, r.brand_name, r.slug,
                    c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email
             FROM tickets t
             JOIN departments d ON d.id = t.department_id
             JOIN resellers r ON r.id = t.reseller_id
             LEFT JOIN clients c ON c.id = t.client_id
             WHERE t.escalated_at IS NOT NULL AND t.status <> 'closed'
             ORDER BY t.escalated_at ASC
             LIMIT " . max(1, $limit)
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
