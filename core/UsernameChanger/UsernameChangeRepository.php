<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * All SQL for the Username Changer. Every status transition is a conditional
 * UPDATE (`… WHERE id = ? AND status = ?`) so two actors racing — a client
 * cancelling while cron claims, an admin approving twice — can never both win.
 */
final class UsernameChangeRepository
{
    /** States a request can still move out of. A request in one of these reserves its name. */
    public const OPEN = ['awaiting_confirmation', 'pending_approval', 'awaiting_payment', 'queued', 'processing'];
    public const FINAL = ['completed', 'failed', 'declined', 'cancelled', 'expired'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    // ---------------------------------------------------------------- requests

    /** @param array<string, mixed> $row */
    public function create(array $row): int
    {
        $now = self::now();
        $row += ['created_at' => $now, 'updated_at' => $now];
        $columns = array_keys($row);

        return (int) $this->db->insert(
            'INSERT INTO username_change_requests (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($row)
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM username_change_requests WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null request joined with client/service facts for screens */
    public function findDetailed(int $id): ?array
    {
        return $this->db->selectOne(
            <<<'SQL'
            SELECT r.*, s.domain, s.username AS current_username, s.status AS service_status, s.product_id,
                   c.first_name, c.last_name, c.email AS client_email, c.company_name,
                   p.name AS product_name, sv.name AS server_name, sv.hostname AS server_hostname
              FROM username_change_requests r
              JOIN services s ON s.id = r.service_id
              JOIN clients c ON c.id = r.client_id
              LEFT JOIN products p ON p.id = s.product_id
              LEFT JOIN servers sv ON sv.id = r.server_id
             WHERE r.id = ?
            SQL,
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByTokenHash(string $hash): ?array
    {
        return $this->db->selectOne('SELECT * FROM username_change_requests WHERE confirm_token_hash = ?', [$hash]);
    }

    /** @return array<string, mixed>|null */
    public function openForService(int $serviceId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM username_change_requests WHERE service_id = ? AND status IN (' . self::placeholders(self::OPEN) . ') ORDER BY id DESC LIMIT 1',
            array_merge([$serviceId], self::OPEN)
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forService(int $serviceId, int $limit = 20): array
    {
        return $this->db->select(
            'SELECT * FROM username_change_requests WHERE service_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$serviceId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function byInvoice(int $invoiceId): array
    {
        return $this->db->select('SELECT * FROM username_change_requests WHERE invoice_id = ?', [$invoiceId]);
    }

    /**
     * Completed client/store-initiated changes on the service, and when the
     * latest one completed. Admin changes are free and never use up the
     * client's allowance.
     *
     * @return array{count: int, last: ?string}
     */
    public function completedStats(int $serviceId): array
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS n, MAX(completed_at) AS last FROM username_change_requests
              WHERE service_id = ? AND status = 'completed' AND requested_by_type <> 'admin'",
            [$serviceId]
        );

        return ['count' => (int) ($row['n'] ?? 0), 'last' => ($row['last'] ?? null) ? (string) $row['last'] : null];
    }

    /**
     * Moves a request from one state to another, but only if it is still in
     * $from. Returns whether this caller won.
     *
     * @param string|array<int, string> $from
     * @param array<string, mixed>      $set
     */
    public function transition(int $id, string|array $from, string $to, array $set = []): bool
    {
        $from = (array) $from;
        $set['status'] = $to;
        $set['updated_at'] = self::now();
        $assign = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($set)));

        return $this->db->update(
            "UPDATE username_change_requests SET {$assign} WHERE id = ? AND status IN (" . self::placeholders($from) . ')',
            array_merge(array_values($set), [$id], $from)
        ) === 1;
    }

    /** @param array<string, mixed> $set */
    public function patch(int $id, array $set): void
    {
        $set['updated_at'] = self::now();
        $assign = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($set)));
        $this->db->update("UPDATE username_change_requests SET {$assign} WHERE id = ?", array_merge(array_values($set), [$id]));
    }

    /** The execution claim (plan §8 step 1). */
    public function claim(int $id, string $lockToken): bool
    {
        $now = self::now();

        return $this->db->update(
            "UPDATE username_change_requests SET status = 'processing', lock_token = ?, locked_at = ?, attempts = attempts + 1, updated_at = ?
              WHERE id = ? AND status = 'queued'",
            [$lockToken, $now, $now, $id]
        ) === 1;
    }

    /** Claims the right to credit reseller margins for this request, once. */
    public function claimFeeCredit(int $id): bool
    {
        return $this->db->update(
            'UPDATE username_change_requests SET fee_credited_at = ? WHERE id = ? AND fee_credited_at IS NULL',
            [self::now(), $id]
        ) === 1;
    }

    public function claimFeeReversal(int $id): bool
    {
        return $this->db->update(
            'UPDATE username_change_requests SET fee_reversed_at = ? WHERE id = ? AND fee_credited_at IS NOT NULL AND fee_reversed_at IS NULL',
            [self::now(), $id]
        ) === 1;
    }

    /** @return array<int, array<string, mixed>> queued requests whose time has come */
    public function due(int $limit): array
    {
        return $this->db->select(
            "SELECT * FROM username_change_requests WHERE status = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id LIMIT " . max(1, $limit),
            [self::now()]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function expiredConfirmations(): array
    {
        return $this->db->select(
            "SELECT * FROM username_change_requests WHERE status = 'awaiting_confirmation' AND confirm_expires_at IS NOT NULL AND confirm_expires_at < ? LIMIT 500",
            [self::now()]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function staleLocks(int $minutes): array
    {
        return $this->db->select(
            "SELECT * FROM username_change_requests WHERE status = 'processing' AND locked_at < ? LIMIT 100",
            [(new DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s')]
        );
    }

    /** @return array<int, array<string, mixed>> awaiting payment, with the invoice's state */
    public function awaitingPayment(): array
    {
        return $this->db->select(
            "SELECT r.*, i.status AS invoice_status FROM username_change_requests r
               LEFT JOIN invoices i ON i.id = r.invoice_id
              WHERE r.status = 'awaiting_payment' LIMIT 500"
        );
    }

    public function prune(int $days): int
    {
        $before = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

        return $this->db->delete(
            "DELETE FROM username_change_requests
              WHERE status IN ('completed', 'declined', 'cancelled', 'expired') AND (sync_state IS NULL OR sync_state <> 'mismatch') AND updated_at < ?",
            [$before]
        );
    }

    /**
     * The admin queue, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(?string $status, string $q, ?int $resellerId = null, bool $onlyStore = false, int $limit = 100, int $offset = 0): array
    {
        [$where, $bindings] = $this->filters($status, $q, $resellerId, $onlyStore);

        return $this->db->select(
            "SELECT r.*, s.domain, c.first_name, c.last_name, c.email AS client_email
               FROM username_change_requests r
               JOIN services s ON s.id = r.service_id
               JOIN clients c ON c.id = r.client_id
              {$where}
              ORDER BY r.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $bindings
        );
    }

    /** @return array<string, int> status => count */
    public function countsByStatus(?int $resellerId = null): array
    {
        $rows = $resellerId === null
            ? $this->db->select('SELECT status, COUNT(*) AS n FROM username_change_requests GROUP BY status')
            : $this->db->select('SELECT status, COUNT(*) AS n FROM username_change_requests WHERE reseller_id = ? GROUP BY status', [$resellerId]);
        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    public function countSince(string $since, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM username_change_requests WHERE created_at >= ?';
        $bind = [$since];

        if ($status !== null) {
            $sql = 'SELECT COUNT(*) AS n FROM username_change_requests WHERE completed_at >= ? AND status = ?';
            $bind[] = $status;
        }

        return (int) ($this->db->selectOne($sql, $bind)['n'] ?? 0);
    }

    public function mismatchCount(): int
    {
        return (int) ($this->db->selectOne("SELECT COUNT(*) AS n FROM username_change_requests WHERE sync_state = 'mismatch'")['n'] ?? 0);
    }

    public function pendingCount(?int $resellerId = null): int
    {
        if ($resellerId === null) {
            return (int) ($this->db->selectOne("SELECT COUNT(*) AS n FROM username_change_requests WHERE status IN ('pending_approval', 'failed') OR sync_state = 'mismatch'")['n'] ?? 0);
        }

        return (int) ($this->db->selectOne("SELECT COUNT(*) AS n FROM username_change_requests WHERE reseller_id = ? AND status = 'pending_approval'", [$resellerId])['n'] ?? 0);
    }

    // ------------------------------------------------------------------ events

    public function event(int $requestId, string $event, string $actorType, ?int $actorId = null, ?string $ip = null, ?string $detail = null): void
    {
        $this->db->insert(
            'INSERT INTO username_change_events (request_id, event, actor_type, actor_id, ip, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$requestId, $event, $actorType, $actorId, $ip, $detail === null ? null : mb_substr($detail, 0, 4000), self::now()]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function events(int $requestId): array
    {
        return $this->db->select('SELECT * FROM username_change_events WHERE request_id = ? ORDER BY id', [$requestId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function auditLog(string $q, int $limit = 200): array
    {
        $q = trim($q);

        if ($q === '') {
            return $this->db->select(
                'SELECT e.*, r.old_username, r.new_username, r.service_id FROM username_change_events e JOIN username_change_requests r ON r.id = e.request_id ORDER BY e.id DESC LIMIT ' . max(1, $limit)
            );
        }

        $like = '%' . $q . '%';

        return $this->db->select(
            'SELECT e.*, r.old_username, r.new_username, r.service_id FROM username_change_events e JOIN username_change_requests r ON r.id = e.request_id
              WHERE e.event LIKE ? OR e.detail LIKE ? OR r.old_username LIKE ? OR r.new_username LIKE ? OR e.ip LIKE ? OR CAST(r.service_id AS CHAR) = ?
              ORDER BY e.id DESC LIMIT ' . max(1, $limit),
            [$like, $like, $like, $like, $like, $q]
        );
    }

    public function pruneEvents(int $days): int
    {
        $before = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

        return $this->db->delete(
            "DELETE e FROM username_change_events e JOIN username_change_requests r ON r.id = e.request_id
              WHERE e.created_at < ? AND r.status IN ('completed', 'declined', 'cancelled', 'expired')",
            [$before]
        );
    }

    // --------------------------------------------------------------- policies

    /** @return array<string, mixed>|null */
    public function policy(string $scope, int $scopeId): ?array
    {
        return $this->db->selectOne('SELECT * FROM username_change_policies WHERE scope = ? AND scope_id = ?', [$scope, $scopeId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function policies(string $scope): array
    {
        return $this->db->select('SELECT * FROM username_change_policies WHERE scope = ? ORDER BY scope_id', [$scope]);
    }

    /** @param array<string, mixed> $fields NULL values mean "inherit" */
    public function savePolicy(string $scope, int $scopeId, array $fields): void
    {
        $allowed = ['enabled', 'max_changes', 'cooldown_days', 'approval', 'allow_db_rename', 'client_mode', 'extra_changes', 'fee', 'note'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        $existing = $this->policy($scope, $scopeId);
        $now = self::now();

        if ($existing === null) {
            $columns = array_merge(['scope', 'scope_id', 'updated_at'], array_keys($fields));
            $this->db->insert(
                'INSERT INTO username_change_policies (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
                array_merge([$scope, $scopeId, $now], array_values($fields))
            );

            return;
        }

        if ($fields === []) {
            return;
        }

        $assign = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($fields)));
        $this->db->update(
            "UPDATE username_change_policies SET {$assign}, updated_at = ? WHERE id = ?",
            array_merge(array_values($fields), [$now, (int) $existing['id']])
        );
    }

    public function deletePolicy(string $scope, int $scopeId): void
    {
        $this->db->delete('DELETE FROM username_change_policies WHERE scope = ? AND scope_id = ?', [$scope, $scopeId]);
    }

    // ---------------------------------------------------------------- throttle

    /** True when the action is allowed (and counts it). */
    public function hit(string $key, int $max, int $windowSeconds): bool
    {
        $now = new DateTimeImmutable();
        $row = $this->db->selectOne('SELECT hits, window_start FROM username_change_throttle WHERE throttle_key = ?', [$key]);

        if ($row === null || (new DateTimeImmutable((string) $row['window_start']))->getTimestamp() + $windowSeconds < $now->getTimestamp()) {
            $this->db->statement(
                'REPLACE INTO username_change_throttle (throttle_key, hits, window_start) VALUES (?, 1, ?)',
                [$key, $now->format('Y-m-d H:i:s')]
            );

            return true;
        }

        if ((int) $row['hits'] >= $max) {
            return false;
        }

        $this->db->update('UPDATE username_change_throttle SET hits = hits + 1 WHERE throttle_key = ?', [$key]);

        return true;
    }

    public function pruneThrottle(): void
    {
        $this->db->delete('DELETE FROM username_change_throttle WHERE window_start < ?', [(new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')]);
    }

    /** @param array<int, mixed> $values */
    public static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function filters(?string $status, string $q, ?int $resellerId, bool $onlyStore): array
    {
        $where = [];
        $bind = [];

        if ($resellerId !== null) {
            $where[] = 'r.reseller_id = ?';
            $bind[] = $resellerId;
        } elseif ($onlyStore) {
            $where[] = 'r.reseller_id IS NOT NULL';
        }

        if ($status === 'open') {
            $where[] = 'r.status IN (' . self::placeholders(self::OPEN) . ')';
            $bind = array_merge($bind, self::OPEN);
        } elseif ($status === 'mismatch') {
            $where[] = "r.sync_state = 'mismatch'";
        } elseif ($status !== null && $status !== '') {
            $where[] = 'r.status = ?';
            $bind[] = $status;
        }

        $q = trim($q);

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(r.old_username LIKE ? OR r.new_username LIKE ? OR s.domain LIKE ? OR c.email LIKE ? OR CONCAT(c.first_name, \' \', c.last_name) LIKE ? OR CAST(r.service_id AS CHAR) = ? OR CAST(r.id AS CHAR) = ?)';
            array_push($bind, $like, $like, $like, $like, $like, $q, ltrim($q, '#'));
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $bind];
    }
}
