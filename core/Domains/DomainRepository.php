<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Database;
use DateTimeImmutable;

final class DomainRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM domains WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $domainName): ?array
    {
        return $this->db->selectOne('SELECT * FROM domains WHERE domain_name = ?', [$domainName]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select('SELECT * FROM domains WHERE client_id = ? ORDER BY id DESC', [$clientId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        return $this->db->select('SELECT * FROM domains WHERE order_id = ?', [$orderId]);
    }

    /** @return array{all: int, active: int, pending: int, expired: int} */
    public function countByStatus(): array
    {
        $rows = $this->db->select('SELECT status, COUNT(*) AS count FROM domains GROUP BY status');
        $counts = ['all' => 0, 'active' => 0, 'pending' => 0, 'expired' => 0];

        foreach ($rows as $row) {
            $cnt = (int) $row['count'];
            $counts['all'] += $cnt;
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] += $cnt;
            }
        }

        return $counts;
    }

    /** @return array<string, int> registrar_slug => active_domain_count */
    public function countActiveByRegistrar(): array
    {
        $rows = $this->db->select("SELECT registrar_slug, COUNT(*) AS count FROM domains WHERE status = 'active' GROUP BY registrar_slug");
        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row['registrar_slug']] = (int) $row['count'];
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(?string $status = null): array
    {
        $where = $status !== null ? 'WHERE d.status = ?' : '';
        $bindings = $status !== null ? [$status] : [];

        return $this->db->select(
            <<<SQL
            SELECT d.*, c.email AS client_email, c.first_name, c.last_name
            FROM domains d
            JOIN clients c ON c.id = d.client_id
            {$where}
            ORDER BY CASE 
                WHEN d.status = 'active' THEN 1 
                WHEN d.status = 'pending' THEN 2 
                WHEN d.status = 'expired' THEN 3 
                ELSE 4 
            END, d.id DESC
            SQL,
            $bindings
        );
    }

    /**
     * Paginated, per-column-filterable domain list for the admin Domains page.
     *
     * @param array<string, string> $filters sanitised `filters[]` bag (see Table\TableFilters)
     * @param array{column: string, dir: string}|null $sort
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(?string $status = null, int $page = 1, int $perPage = 15, array $filters = [], ?array $sort = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $bindings = [];

        if ($status !== null) {
            $conditions[] = 'd.status = ?';
            $bindings[] = $status;
        }

        [$filterWhere, $filterBindings] = \CodeVault\Table\TableFilters::where($filters, [
            'domain'    => ['d.domain_name', 'like'],
            'client'    => [['c.first_name', 'c.last_name', 'c.email'], 'like'],
            'tld'       => ['d.tld', 'like'],
            'registrar' => ['d.registrar_slug', 'like'],
            'expiry'    => ['d.expiry_date', 'like'],
            'status'    => ['d.status', 'eq'],
        ]);

        if ($filterWhere !== '') {
            $conditions[] = $filterWhere;
            $bindings = array_merge($bindings, $filterBindings);
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $sortable = [
            'domain'    => 'd.domain_name',
            'client'    => 'c.last_name',
            'registrar' => 'd.registrar_slug',
            'expiry'    => 'd.expiry_date',
            'status'    => 'd.status',
        ];
        $orderBy = \CodeVault\Table\TableFilters::orderBy($sortable, $sort);
        if ($orderBy === '') {
            $orderBy = <<<'SQL'
            ORDER BY CASE
                WHEN d.status = 'active' THEN 1
                WHEN d.status = 'pending' THEN 2
                WHEN d.status = 'expired' THEN 3
                ELSE 4
            END, d.id DESC
            SQL;
        }

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS c FROM domains d JOIN clients c ON c.id = d.client_id {$where}",
            $bindings
        )['c'] ?? 0);

        $data = $this->db->select(
            <<<SQL
            SELECT d.*, c.email AS client_email, c.first_name, c.last_name
            FROM domains d
            JOIN clients c ON c.id = d.client_id
            {$where}
            {$orderBy}
            LIMIT {$perPage} OFFSET {$offset}
            SQL,
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * Domains due for renewal within `$daysAhead` days — active only,
     * same shape as ServiceRepository::dueForBilling().
     *
     * @return array<int, array<string, mixed>>
     */
    public function dueForRenewal(int $daysAhead): array
    {
        $cutoff = (new DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');

        return $this->db->select(
            "SELECT * FROM domains WHERE status = 'active' AND auto_renew = 1 AND next_due_date <= ?",
            [$cutoff]
        );
    }

    /** Dashboard tile (R17) — same cutoff as dueForRenewal(), COUNT only. */
    public function countDueForRenewal(int $daysAhead): int
    {
        $cutoff = (new DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM domains WHERE status = 'active' AND auto_renew = 1 AND next_due_date <= ?",
            [$cutoff]
        );

        return (int) ($row['c'] ?? 0);
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $domainName = strtolower((string) $fields['domain_name']);
        $tld = $fields['tld'] ?? substr($domainName, strpos($domainName, '.') + 1);

        return (int) $this->db->insert(
            'INSERT INTO domains (client_id, order_id, domain_name, tld, registrar_slug, status, expiry_date, next_due_date, auto_renew, amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['client_id'],
                $fields['order_id'] ?? null,
                $domainName,
                $tld,
                $fields['registrar_slug'],
                $fields['status'] ?? 'pending',
                $fields['expiry_date'] ?? null,
                $fields['next_due_date'],
                $fields['auto_renew'] ?? 1,
                $fields['amount'] ?? 0,
                $now,
                $now,
            ]
        );
    }

    public function activate(int $id, string $registrarDomainId, string $registrationDate, string $expiryDate): void
    {
        $this->db->update(
            'UPDATE domains SET status = ?, registrar_domain_id = ?, registration_date = ?, expiry_date = ?, updated_at = ? WHERE id = ?',
            ['active', $registrarDomainId, $registrationDate, $expiryDate, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->update(
            'UPDATE domains SET status = ?, updated_at = ? WHERE id = ?',
            [$status, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** @param array<string, mixed> $fields */
    public function updateStatusAndDates(int $id, array $fields): void
    {
        $this->db->update(
            'UPDATE domains SET status = ?, registration_date = ?, expiry_date = ?, next_due_date = ?, auto_renew = ?, amount = ?, updated_at = ? WHERE id = ?',
            [
                $fields['status'],
                $fields['registration_date'] ?? null,
                $fields['expiry_date'] ?? null,
                $fields['next_due_date'] ?? null,
                !empty($fields['auto_renew']) ? 1 : 0,
                $fields['amount'] ?? 0,
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    /**
     * Re-point a domain at a different registrar. registrar_domain_id and
     * registrar_contact_id are opaque handles owned by the OLD registrar —
     * they mean nothing (and could be harmful) to the new one, so they are
     * cleared rather than carried over.
     */
    public function updateRegistrar(int $id, string $registrarSlug): void
    {
        $this->db->update(
            'UPDATE domains SET registrar_slug = ?, registrar_domain_id = NULL, registrar_contact_id = NULL, updated_at = ? WHERE id = ?',
            [$registrarSlug, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function advanceRenewal(int $id, string $newExpiryDate, string $newNextDueDate): void
    {
        $this->db->update(
            'UPDATE domains SET expiry_date = ?, next_due_date = ?, updated_at = ? WHERE id = ?',
            [$newExpiryDate, $newNextDueDate, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updateContactId(int $id, string $registrarContactId): void
    {
        $this->db->update(
            'UPDATE domains SET registrar_contact_id = ?, updated_at = ? WHERE id = ?',
            [$registrarContactId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updateRegistrarDomainId(int $id, string $registrarDomainId): void
    {
        $this->db->update(
            'UPDATE domains SET registrar_domain_id = ?, updated_at = ? WHERE id = ?',
            [$registrarDomainId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updateContactData(int $id, array $contact): void
    {
        $this->db->update(
            'UPDATE domains SET contact_data = ?, updated_at = ? WHERE id = ?',
            [json_encode($contact), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updateNameservers(int $id, array $nameservers): void
    {
        $this->db->update(
            'UPDATE domains SET nameservers = ?, updated_at = ? WHERE id = ?',
            [json_encode(array_values($nameservers)), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setLock(int $id, bool $locked): void
    {
        $this->db->update(
            'UPDATE domains SET registrar_lock_enabled = ?, updated_at = ? WHERE id = ?',
            [$locked ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function setIdProtection(int $id, bool $enabled): void
    {
        $this->db->update(
            'UPDATE domains SET id_protection_enabled = ?, updated_at = ? WHERE id = ?',
            [$enabled ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** @param string|null $message null clears a previously-recorded error */
    public function recordProvisioningError(int $id, ?string $message): void
    {
        $this->db->update(
            'UPDATE domains SET provisioning_error = ?, updated_at = ? WHERE id = ?',
            [$message, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function allForRegistrar(string $registrarSlug): array
    {
        $pattern = '%' . strtolower($registrarSlug) . '%';
        return $this->db->select(
            "SELECT d.*, c.email AS client_email, c.first_name, c.last_name FROM domains d JOIN clients c ON c.id = d.client_id WHERE d.registrar_slug = ? OR LOWER(d.registrar_slug) LIKE ? ORDER BY CASE WHEN d.status = 'active' THEN 1 WHEN d.status = 'pending' THEN 2 WHEN d.status = 'expired' THEN 3 ELSE 4 END, d.id DESC",
            [$registrarSlug, $pattern]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getChildNameservers(int $domainId): array
    {
        return $this->db->select(
            'SELECT * FROM domain_child_nameservers WHERE domain_id = ? ORDER BY hostname ASC',
            [$domainId]
        );
    }

    public function addChildNameserver(int $domainId, string $hostname, string $ip): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        return (int) $this->db->insert(
            'INSERT INTO domain_child_nameservers (domain_id, hostname, ip_address, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$domainId, $hostname, $ip, $now, $now]
        );
    }

    public function deleteChildNameserver(int $domainId, int $childNsId): void
    {
        $this->db->delete(
            'DELETE FROM domain_child_nameservers WHERE id = ? AND domain_id = ?',
            [$childNsId, $domainId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getDnsRecords(int $domainId): array
    {
        return $this->db->select(
            'SELECT * FROM domain_dns_records WHERE domain_id = ? ORDER BY type ASC, name ASC',
            [$domainId]
        );
    }

    public function addDnsRecord(int $domainId, string $type, string $name, string $content, int $priority = 10, int $ttl = 3600): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        return (int) $this->db->insert(
            'INSERT INTO domain_dns_records (domain_id, type, name, content, priority, ttl, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$domainId, strtoupper($type), $name, $content, $priority, $ttl, $now, $now]
        );
    }

    public function deleteDnsRecord(int $domainId, int $recordId): void
    {
        $this->db->delete(
            'DELETE FROM domain_dns_records WHERE id = ? AND domain_id = ?',
            [$recordId, $domainId]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM domain_child_nameservers WHERE domain_id = ?', [$id]);
        $this->db->delete('DELETE FROM domain_dns_records WHERE domain_id = ?', [$id]);
        $this->db->delete('DELETE FROM domains WHERE id = ?', [$id]);
    }

    /**
     * Domains whose raw expiry_date has already passed — candidates for
     * DomainPruningJob. Deliberately broad: grace/redemption are per-TLD (from
     * domain_pricing), so the job applies each domain's own exact window on
     * top of this, the same two-step "coarse fetch, then precise per-row
     * check" shape ServiceTerminationJob uses for termination grace.
     *
     * transferred_away is excluded — that domain left to another registrar,
     * it isn't sitting unredeemed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function expiredSince(string $cutoffDate): array
    {
        return $this->db->select(
            "SELECT * FROM domains WHERE expiry_date IS NOT NULL AND expiry_date < ? AND status != 'transferred_away'",
            [$cutoffDate]
        );
    }

    /** @param array<int, int> $ids */
    public function bulkDelete(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        $count = 0;
        foreach ($ids as $id) {
            $this->delete((int) $id);
            $count++;
        }
        return $count;
    }

    /** @param array<int, int> $ids */
    public function bulkUpdateStatus(array $ids, string $status): int
    {
        if (empty($ids)) {
            return 0;
        }
        $count = 0;
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($ids as $id) {
            $this->db->update(
                'UPDATE domains SET status = ?, updated_at = ? WHERE id = ?',
                [$status, $now, (int) $id]
            );
            $count++;
        }
        return $count;
    }

    /**
     * Re-point several domains at a different registrar. Same semantics as
     * updateRegistrar(): registrar_domain_id / registrar_contact_id are
     * opaque handles owned by the OLD registrar and are cleared so they can't
     * leak into the new one.
     *
     * @param array<int, int> $ids
     */
    public function bulkUpdateRegistrar(array $ids, string $registrarSlug): int
    {
        if (empty($ids)) {
            return 0;
        }
        $count = 0;
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($ids as $id) {
            $this->db->update(
                'UPDATE domains SET registrar_slug = ?, registrar_domain_id = NULL, registrar_contact_id = NULL, updated_at = ? WHERE id = ?',
                [$registrarSlug, $now, (int) $id]
            );
            $count++;
        }
        return $count;
    }
}
