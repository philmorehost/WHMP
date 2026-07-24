<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

final class ServiceRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            <<<SQL
            SELECT s.*, c.email AS client_email, c.first_name, c.last_name, c.company_name, c.currency_id AS client_currency_id, cu.code AS currency_code, cu.symbol AS currency_symbol, cu.exchange_rate AS currency_rate
            FROM services s
            JOIN clients c ON c.id = s.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            WHERE s.id = ?
            SQL,
            [$id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select(
            <<<SQL
            SELECT s.*, cu.symbol AS currency_symbol, cu.exchange_rate AS currency_rate
            FROM services s
            JOIN clients c ON c.id = s.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            WHERE s.client_id = ? 
            ORDER BY s.id DESC
            SQL,
            [$clientId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        return $this->db->select('SELECT * FROM services WHERE order_id = ?', [$orderId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(?string $status = null): array
    {
        $where = $status !== null ? 'WHERE s.status = ?' : '';
        $bindings = $status !== null ? [$status] : [];

        return $this->db->select(
            <<<SQL
            SELECT s.*, c.email AS client_email, c.first_name, c.last_name, cu.symbol AS currency_symbol, cu.exchange_rate AS currency_rate
            FROM services s
            JOIN clients c ON c.id = s.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            {$where}
            ORDER BY s.next_due_date
            SQL,
            $bindings
        );
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(?string $status = null, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = $status !== null ? 'WHERE s.status = ?' : '';
        $bindings = $status !== null ? [$status] : [];

        $total = (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM services s {$where}", $bindings)['c'] ?? 0);

        $data = $this->db->select(
            <<<SQL
            SELECT s.*, c.email AS client_email, c.first_name, c.last_name, cu.symbol AS currency_symbol, cu.exchange_rate AS currency_rate
            FROM services s
            JOIN clients c ON c.id = s.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            {$where}
            ORDER BY s.id DESC
            LIMIT {$perPage} OFFSET {$offset}
            SQL,
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * Services due for renewal within `$daysAhead` days (blueprint §4.4
     * "recurring generation ahead of due date"), active only — a
     * suspended/cancelled/terminated service doesn't get rebilled.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dueForBilling(int $daysAhead): array
    {
        $cutoff = (new DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');

        return $this->db->select(
            "SELECT * FROM services WHERE status = 'active' AND next_due_date <= ?",
            [$cutoff]
        );
    }

    /** Dashboard tile (R17) — same cutoff as dueForBilling(), COUNT only. */
    public function countDueForBilling(int $daysAhead): int
    {
        $cutoff = (new DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM services WHERE status = 'active' AND next_due_date <= ?",
            [$cutoff]
        );

        return (int) ($row['c'] ?? 0);
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO services (client_id, order_id, product_id, product_name, billing_cycle, amount, domain, hostname, password, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['client_id'],
                $fields['order_id'] ?? null,
                $fields['product_id'],
                $fields['product_name'],
                $fields['billing_cycle'],
                $fields['amount'],
                $fields['domain'] ?? null,
                $fields['hostname'] ?? null,
                $fields['password'] ?? null,
                $fields['status'] ?? 'pending',
                $fields['next_due_date'],
                $now,
                $now,
            ]
        );
    }

    public function activate(int $id): void
    {
        $this->setStatus($id, 'active');
    }

    public function suspend(int $id): void
    {
        $this->setStatus($id, 'suspended');
    }

    public function unsuspend(int $id): void
    {
        $this->setStatus($id, 'active');
    }

    public function terminate(int $id): void
    {
        $this->setStatus($id, 'terminated');
    }

    public function cancel(int $id): void
    {
        $this->setStatus($id, 'cancelled');
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->setStatus($id, $status);
    }

    public function assignServer(int $id, int $serverId, string $username): void
    {
        $this->db->update(
            'UPDATE services SET server_id = ?, username = ?, updated_at = ? WHERE id = ?',
            [$serverId, $username, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** @param string|null $message null clears a previously-recorded error */
    public function recordProvisioningError(int $id, ?string $message): void
    {
        $this->db->update(
            'UPDATE services SET provisioning_error = ?, updated_at = ? WHERE id = ?',
            [$message, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    private function setStatus(int $id, string $status): void
    {
        $this->db->update(
            'UPDATE services SET status = ?, updated_at = ? WHERE id = ?',
            [$status, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function advanceNextDueDate(int $id, string $newDueDate): void
    {
        // Clearing renewal_reminded_at here (not just setting the new date)
        // is what lets RenewalReminderJob email again for the *next* cycle —
        // without it, a service would only ever get reminded once, ever.
        $this->db->update(
            'UPDATE services SET next_due_date = ?, renewal_reminded_at = NULL, updated_at = ? WHERE id = ?',
            [$newDueDate, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function dueForReminder(int $daysAhead): array
    {
        $cutoff = (new DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');

        return $this->db->select(
            "SELECT * FROM services WHERE status = 'active' AND next_due_date <= ? AND renewal_reminded_at IS NULL",
            [$cutoff]
        );
    }

    public function stampReminded(int $id): void
    {
        $this->db->update(
            'UPDATE services SET renewal_reminded_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Advances a date by one billing cycle — the shared step function for
     * both the recurring billing cron and proration's "next cycle" calc.
     */
    public static function nextCycleDate(string $fromDate, string $billingCycle): string
    {
        $interval = match ($billingCycle) {
            'monthly' => '+1 month',
            'quarterly' => '+3 months',
            'semi_annually' => '+6 months',
            'annually' => '+1 year',
            'biennially' => '+2 years',
            'triennially' => '+3 years',
            default => '+1 month',
        };

        return (new DateTimeImmutable($fromDate . ' ' . $interval))->format('Y-m-d');
    }

    /**
     * The inverse of nextCycleDate() — used by proration to find where the
     * current billing cycle started, so it can measure the cycle's total
     * length in days.
     */
    public static function previousCycleDate(string $fromDate, string $billingCycle): string
    {
        $interval = match ($billingCycle) {
            'monthly' => '-1 month',
            'quarterly' => '-3 months',
            'semi_annually' => '-6 months',
            'annually' => '-1 year',
            'biennially' => '-2 years',
            'triennially' => '-3 years',
            default => '-1 month',
        };

        return (new DateTimeImmutable($fromDate . ' ' . $interval))->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        $this->db->update(
            'UPDATE services SET product_id = ?, product_name = ?, billing_cycle = ?, amount = ?, updated_at = ? WHERE id = ?',
            [
                $fields['product_id'],
                $fields['product_name'],
                $fields['billing_cycle'],
                $fields['amount'],
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    private function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $this->db->statement('ALTER TABLE services ADD COLUMN dedicated_ip VARCHAR(255) NULL AFTER username');
        } catch (\Throwable) {}
        try {
            $this->db->statement('ALTER TABLE services ADD COLUMN assigned_ips TEXT NULL AFTER dedicated_ip');
        } catch (\Throwable) {}
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateDetails(int $id, array $fields): void
    {
        $this->ensureSchema();
        $this->db->update(
            'UPDATE services SET username = ?, domain = ?, hostname = ?, dedicated_ip = ?, assigned_ips = ?, server_id = ?, updated_at = ? WHERE id = ?',
            [
                $fields['username'] ?? null,
                $fields['domain'] ?? null,
                $fields['hostname'] ?? null,
                $fields['dedicated_ip'] ?? null,
                $fields['assigned_ips'] ?? null,
                $fields['server_id'] ?? null,
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM services WHERE id = ?', [$id]);
    }

    /** @param array<int, int> $ids */
    public function bulkDelete(array $ids): int
    {
        $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->delete("DELETE FROM services WHERE id IN ({$placeholders})", $ids);
    }
}
