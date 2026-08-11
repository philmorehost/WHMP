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
            SELECT s.*, p.type AS product_type, cu.symbol AS currency_symbol, cu.exchange_rate AS currency_rate
            FROM services s
            JOIN clients c ON c.id = s.client_id
            LEFT JOIN products p ON p.id = s.product_id
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
    public function paginate(?string $status = null, int $page = 1, int $perPage = 20, string $search = ''): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $bindings = [];

        if ($status !== null) {
            $conditions[] = 's.status = ?';
            $bindings[] = $status;
        }

        // Searches the whole table, not just the page currently on screen —
        // the client-side row filter this replaces could only ever match the
        // 20 rows already rendered, which quietly hid every other match.
        // Domain and hostname are included so an admin can paste a customer's
        // domain straight in, which is usually all they have to go on.
        $search = trim($search);

        if ($search !== '') {
            $conditions[] = '(s.domain LIKE ? OR s.hostname LIKE ? OR s.product_name LIKE ? OR s.username LIKE ?'
                . ' OR c.email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ?'
                . " OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?)";
            $needle = "%{$search}%";
            $bindings = array_merge($bindings, array_fill(0, 9, $needle));
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        // The COUNT has to join clients too, or a search on a client field
        // would filter the rows but leave the total (and page count) wrong.
        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS c FROM services s JOIN clients c ON c.id = s.client_id {$where}",
            $bindings
        )['c'] ?? 0);

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

    /**
     * Services whose due date has passed by more than $graceDays and which
     * still owe money, for the auto-suspension sweep.
     *
     * The unpaid check is a NOT EXISTS against invoices rather than a status
     * flag on the service: a client who pays late is settled the moment the
     * invoice flips to paid, and must not be suspended by a sweep that only
     * looked at next_due_date.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overdueForSuspension(int $graceDays): array
    {
        $cutoff = (new DateTimeImmutable("-{$graceDays} days"))->format('Y-m-d');

        return $this->db->select(
            "SELECT s.* FROM services s
             WHERE s.status = 'active'
               AND s.next_due_date < ?
               AND EXISTS (
                   SELECT 1 FROM invoices i
                   WHERE i.service_id = s.id AND i.status = 'unpaid'
               )",
            [$cutoff]
        );
    }

    /**
     * Expired services eligible for termination, with the product type each
     * one belongs to so the caller can apply the right grace window.
     *
     * Returns candidates past the *shortest* grace in play and lets the caller
     * filter per type — one query instead of one per product type, and a new
     * type can't slip through a hard-coded IN list.
     *
     * Only suspended/active services are returned: already-cancelled or
     * terminated rows must never be re-terminated.
     *
     * @return array<int, array<string, mixed>> service rows plus product_type
     */
    public function expiredForTermination(int $minimumGraceDays): array
    {
        $cutoff = (new DateTimeImmutable("-{$minimumGraceDays} days"))->format('Y-m-d');

        return $this->db->select(
            "SELECT s.*, p.type AS product_type
             FROM services s
             LEFT JOIN products p ON p.id = s.product_id
             WHERE s.status IN ('active', 'suspended')
               AND s.next_due_date < ?
               AND EXISTS (
                   SELECT 1 FROM invoices i
                   WHERE i.service_id = s.id AND i.status = 'unpaid'
               )
             ORDER BY s.next_due_date ASC",
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
            'INSERT INTO services (client_id, order_id, parent_id, product_id, product_name, billing_cycle, amount, domain, hostname, password, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['client_id'],
                $fields['order_id'] ?? null,
                $fields['parent_id'] ?? null,
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

    /**
     * Child add-on services attached to a parent service (services.parent_id).
     *
     * @return array<int, array<string, mixed>>
     */
    public function addonsFor(int $parentServiceId): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT s.*, cu.symbol AS currency_symbol, cu.exchange_rate AS currency_rate
            FROM services s
            LEFT JOIN clients c ON c.id = s.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            WHERE s.parent_id = ?
            ORDER BY s.id ASC
            SQL,
            [$parentServiceId]
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
    /**
     * Stamps the moment this service's access details were emailed, so the
     * admin screen can distinguish a first send from a resend.
     */
    public function stampDetailsSent(int $id): void
    {
        $this->ensureSchema();

        $this->db->update(
            'UPDATE services SET details_sent_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

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
     * A short tag identifying which specific domain/hostname a service line
     * is for, appended to invoice item descriptions (order, renewal, and
     * proration invoices — see CheckoutService, RecurringBillingService,
     * ProrationService). Without this, a client (or admin) with more than
     * one of the same product on their account sees identical line items
     * like "Web Hosting - Basic (Annually)" with no way to tell which
     * invoice belongs to which of their sites.
     */
    public static function invoiceIdentifierSuffix(?string $domain, ?string $hostname): string
    {
        $parts = array_unique(array_filter(
            [trim((string) $domain), trim((string) $hostname)],
            static fn (string $value): bool => $value !== ''
        ));

        return $parts === [] ? '' : ' — ' . implode(', ', $parts);
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
        // Migration 0102 adds this, but an install that predates it (or whose
        // 0102 partially applied) would otherwise fail the moment an admin
        // saves a password — same defensive shape as the two above.
        try {
            $this->db->statement('ALTER TABLE services ADD COLUMN password VARCHAR(255) NULL AFTER hostname');
        } catch (\Throwable) {}
        // Migration 0143. Same reasoning: repairs an install that has the code
        // but not yet the column.
        try {
            $this->db->statement('ALTER TABLE services ADD COLUMN details_sent_at DATETIME NULL AFTER password');
        } catch (\Throwable) {}
    }

    /**
     * Partial update of a service's access details.
     *
     * **Only keys actually present in $fields are written.** A key that is
     * absent is left untouched in the database — it is not nulled. Two things
     * depend on that:
     *
     *  - The password renders masked, so a blank submission means "I didn't
     *    touch it", not "wipe it". Nulling it would destroy the stored
     *    password every time an admin edited an unrelated field.
     *  - Fields the form doesn't render for this product type (a VPS has no
     *    domain, only a hostname) must survive a save rather than be cleared
     *    by their own absence.
     *
     * Passing an explicit null still clears the column, so "blank this out"
     * stays expressible.
     *
     * @param array<string, mixed> $fields
     */
    public function updateDetails(int $id, array $fields): void
    {
        $this->ensureSchema();

        $writable = ['username', 'domain', 'hostname', 'password', 'dedicated_ip', 'assigned_ips', 'server_id'];
        $assignments = [];
        $bindings = [];

        foreach ($writable as $column) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }

            $assignments[] = "{$column} = ?";
            $bindings[] = $fields[$column];
        }

        if ($assignments === []) {
            return;
        }

        $assignments[] = 'updated_at = ?';
        $bindings[] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $bindings[] = $id;

        $this->db->update(
            'UPDATE services SET ' . implode(', ', $assignments) . ' WHERE id = ?',
            $bindings
        );
    }

    /**
     * Rewrites the recurring amount in one go — used by the admin "set to
     * package price" toggle, which backs a service onto its product's current
     * catalog price (WHMCS parity) without going through order/upgrade logic.
     */
    public function updateAmount(int $id, float $amount): void
    {
        $this->db->update(
            'UPDATE services SET amount = ?, updated_at = ? WHERE id = ?',
            [$amount, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM services WHERE id = ?', [$id]);
    }

    /**
     * Services that have sat in 'terminated' status since before the cutoff —
     * candidates for ServicePruningJob. updated_at is the "terminated at"
     * timestamp: terminate() is a dead end for a service (nothing updates it
     * again afterward), so the last write is reliably the termination itself.
     *
     * @return array<int, array<string, mixed>>
     */
    public function terminatedBefore(string $cutoff): array
    {
        return $this->db->select(
            "SELECT id FROM services WHERE status = 'terminated' AND updated_at < ?",
            [$cutoff]
        );
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
