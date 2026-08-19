<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Database;
use DateTimeImmutable;

final class ServerRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT s.*, g.name AS group_name
            FROM servers s
            LEFT JOIN server_groups g ON g.id = s.server_group_id
            ORDER BY s.name
            SQL
        );
    }

    /**
     * Paginated, per-column-filterable server list for the admin Servers page.
     *
     * @param array<string, string> $filters sanitised `filters[]` bag (see Table\TableFilters)
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        // The status column is a boolean flag; map the UI's friendly values.
        if (isset($filters['status'])) {
            $filters['status'] = $filters['status'] === 'active' ? '1' : '0';
        }

        [$filterWhere, $bindings] = \CodeVault\Table\TableFilters::where($filters, [
            'name'     => ['s.name', 'like'],
            'hostname' => ['s.hostname', 'like'],
            'module'   => ['s.module_slug', 'like'],
            'group'    => ['g.name', 'like'],
            'status'   => ['s.active', 'eq'],
        ]);

        $where = $filterWhere === '' ? '' : 'WHERE ' . $filterWhere;

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS c FROM servers s LEFT JOIN server_groups g ON g.id = s.server_group_id {$where}",
            $bindings
        )['c'] ?? 0);

        $data = $this->db->select(
            <<<SQL
            SELECT s.*, g.name AS group_name
            FROM servers s
            LEFT JOIN server_groups g ON g.id = s.server_group_id
            {$where}
            ORDER BY s.name
            LIMIT {$perPage} OFFSET {$offset}
            SQL,
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM servers WHERE id = ?', [$id]);
    }

    /**
     * Active servers in a group, ordered by current active-service count
     * ascending — the simplest possible load-balancing strategy
     * ("least-loaded first"). A real capacity-aware balancer (bandwidth,
     * disk, explicit weight) is a later refinement, not a v1 blocker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeForGroupByLoad(int $groupId): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT s.*, COUNT(sv.id) AS active_service_count
            FROM servers s
            LEFT JOIN services sv ON sv.server_id = s.id AND sv.status = 'active'
            WHERE s.server_group_id = ? AND s.active = 1
            GROUP BY s.id
            ORDER BY active_service_count ASC
            SQL,
            [$groupId]
        );
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO servers (server_group_id, name, hostname, module_slug, api_username, api_token, api_port, use_ssl, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['server_group_id'] ?? null,
                $fields['name'],
                $fields['hostname'],
                $fields['module_slug'],
                $fields['api_username'] ?? null,
                $fields['api_token'] ?? null,
                $fields['api_port'] ?? null,
                $fields['use_ssl'] ?? 1,
                $fields['active'] ?? 1,
                $now,
                $now,
            ]
        );
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $this->db->update(
            'UPDATE servers SET server_group_id = ?, name = ?, hostname = ?, module_slug = ?, api_username = ?, api_token = ?, api_port = ?, use_ssl = ?, active = ?, updated_at = ? WHERE id = ?',
            [
                $fields['server_group_id'] ?? null,
                $fields['name'],
                $fields['hostname'],
                $fields['module_slug'],
                $fields['api_username'] ?? null,
                $fields['api_token'] ?? null,
                $fields['api_port'] ?? null,
                $fields['use_ssl'] ?? 1,
                $fields['active'] ?? 1,
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM servers WHERE id = ?', [$id]);
    }
}
