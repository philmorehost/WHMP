<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;

/**
 * Every read a reseller makes about its own customers.
 *
 * THE STORE IS IN EVERY QUERY
 *
 * Each method takes the store id first and puts `clients.reseller_id = ?` into the SQL
 * itself — never "load the row, then check it". An id from the URL is therefore only
 * ever a row that has already been proved to belong to the store, and a method that
 * forgot the check could not exist without it being visible in the query. The store id
 * always comes from the signed-in reseller (ResellerStoreRepository::forClient), never
 * from the request.
 */
final class ResellerClientDirectory
{
    public const PER_PAGE = 25;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param 'all'|'active'|'suspended'|'unpaid' $filter
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function clients(int $storeId, string $search = '', string $filter = 'all', int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = ['c.reseller_id = ?'];
        $bindings = [$storeId];

        $search = trim($search);
        if ($search !== '') {
            $needle = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = "(c.email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?)";
            array_push($bindings, $needle, $needle, $needle, $needle, $needle);
        }

        $where[] = match ($filter) {
            'active' => "EXISTS (SELECT 1 FROM services s WHERE s.client_id = c.id AND s.status = 'active')",
            'suspended' => "EXISTS (SELECT 1 FROM services s WHERE s.client_id = c.id AND s.status = 'suspended')",
            'unpaid' => "EXISTS (SELECT 1 FROM invoices i WHERE i.client_id = c.id AND i.status = 'unpaid')",
            default => '1 = 1',
        };

        $whereSql = implode(' AND ', $where);

        $total = (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM clients c WHERE {$whereSql}", $bindings)['c'] ?? 0);
        $offset = ($page - 1) * $perPage;

        $data = $this->db->select(
            "SELECT c.id, c.first_name, c.last_name, c.email, c.company_name, c.phone, c.status, c.created_at,
                    (SELECT COUNT(*) FROM services s WHERE s.client_id = c.id) AS services_total,
                    (SELECT COUNT(*) FROM services s WHERE s.client_id = c.id AND s.status = 'active') AS services_active,
                    (SELECT COUNT(*) FROM services s WHERE s.client_id = c.id AND s.status = 'suspended') AS services_suspended,
                    (SELECT COUNT(*) FROM domains d WHERE d.client_id = c.id AND d.status IN ('pending', 'active', 'grace', 'redemption')) AS domains_total,
                    (SELECT COUNT(*) FROM invoices i WHERE i.client_id = c.id AND i.status = 'unpaid') AS invoices_unpaid
               FROM clients c
              WHERE {$whereSql}
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /** @return array{customers: int, services_active: int, services_suspended: int, domains: int, invoices_unpaid: int} */
    public function summary(int $storeId): array
    {
        $row = $this->db->selectOne(
            "SELECT
                (SELECT COUNT(*) FROM clients c WHERE c.reseller_id = ?) AS customers,
                (SELECT COUNT(*) FROM services s JOIN clients c ON c.id = s.client_id WHERE c.reseller_id = ? AND s.status = 'active') AS services_active,
                (SELECT COUNT(*) FROM services s JOIN clients c ON c.id = s.client_id WHERE c.reseller_id = ? AND s.status = 'suspended') AS services_suspended,
                (SELECT COUNT(*) FROM domains d JOIN clients c ON c.id = d.client_id WHERE c.reseller_id = ? AND d.status IN ('pending', 'active', 'grace', 'redemption')) AS domains,
                (SELECT COUNT(*) FROM invoices i JOIN clients c ON c.id = i.client_id WHERE c.reseller_id = ? AND i.status = 'unpaid') AS invoices_unpaid",
            [$storeId, $storeId, $storeId, $storeId, $storeId]
        ) ?? [];

        return [
            'customers' => (int) ($row['customers'] ?? 0),
            'services_active' => (int) ($row['services_active'] ?? 0),
            'services_suspended' => (int) ($row['services_suspended'] ?? 0),
            'domains' => (int) ($row['domains'] ?? 0),
            'invoices_unpaid' => (int) ($row['invoices_unpaid'] ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    public function client(int $storeId, int $clientId): ?array
    {
        return $this->db->selectOne('SELECT * FROM clients WHERE id = ? AND reseller_id = ? LIMIT 1', [$clientId, $storeId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function services(int $storeId, int $clientId): array
    {
        return $this->db->select(
            'SELECT s.* FROM services s JOIN clients c ON c.id = s.client_id
              WHERE s.client_id = ? AND c.reseller_id = ?
              ORDER BY FIELD(s.status, \'active\', \'suspended\', \'pending\', \'cancelled\', \'terminated\'), s.id DESC',
            [$clientId, $storeId]
        );
    }

    /** @return array<string, mixed>|null */
    public function service(int $storeId, int $serviceId): ?array
    {
        return $this->db->selectOne(
            'SELECT s.* FROM services s JOIN clients c ON c.id = s.client_id WHERE s.id = ? AND c.reseller_id = ? LIMIT 1',
            [$serviceId, $storeId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function domains(int $storeId, int $clientId): array
    {
        return $this->db->select(
            'SELECT d.* FROM domains d JOIN clients c ON c.id = d.client_id
              WHERE d.client_id = ? AND c.reseller_id = ?
              ORDER BY d.expiry_date IS NULL, d.expiry_date ASC, d.id DESC',
            [$clientId, $storeId]
        );
    }

    /** @return array<string, mixed>|null */
    public function domain(int $storeId, int $domainId): ?array
    {
        return $this->db->selectOne(
            'SELECT d.* FROM domains d JOIN clients c ON c.id = d.client_id WHERE d.id = ? AND c.reseller_id = ? LIMIT 1',
            [$domainId, $storeId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function invoices(int $storeId, int $clientId, int $limit = 20): array
    {
        return $this->db->select(
            'SELECT i.id, i.status, i.total, i.currency_id, i.currency_rate, i.due_date, i.paid_at, i.created_at
               FROM invoices i JOIN clients c ON c.id = i.client_id
              WHERE i.client_id = ? AND c.reseller_id = ?
              ORDER BY i.id DESC
              LIMIT ' . max(1, min(100, $limit)),
            [$clientId, $storeId]
        );
    }

    /**
     * The customer's tickets on THIS store's desk — the same rows the reseller's
     * support page shows, so every link here opens there.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tickets(int $storeId, int $clientId, int $limit = 10): array
    {
        return $this->db->select(
            'SELECT t.id, t.subject, t.status, t.updated_at, t.escalated_at
               FROM tickets t JOIN clients c ON c.id = t.client_id
              WHERE t.client_id = ? AND t.reseller_id = ? AND c.reseller_id = ?
              ORDER BY t.updated_at DESC
              LIMIT ' . max(1, min(50, $limit)),
            [$clientId, $storeId, $storeId]
        );
    }
}
