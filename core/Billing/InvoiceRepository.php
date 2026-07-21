<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

final class InvoiceRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM invoices WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function items(int $invoiceId): array
    {
        return $this->db->select('SELECT * FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select('SELECT * FROM invoices WHERE client_id = ? ORDER BY id DESC', [$clientId]);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(?string $status = null, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = '';
        $bindings = [];

        if ($status !== null) {
            $where = 'WHERE i.status = ?';
            $bindings = [$status];
        }

        $total = (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM invoices i {$where}", $bindings)['c'] ?? 0);

        // currency_id IS NULL means "locked to the base currency at
        // creation time" (see CurrencyService::lockColumns) — it must
        // resolve to the *default* currency, never the client's current
        // preference, or an old base-currency invoice would show the
        // client's later-changed currency's symbol next to a total that
        // was never actually converted into it.
        $data = $this->db->select(
            <<<SQL
            SELECT i.*, c.email AS client_email, c.first_name, c.last_name, curr.code AS currency_code, curr.symbol AS currency_symbol
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            LEFT JOIN currencies curr ON curr.id = COALESCE(i.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            {$where}
            ORDER BY i.id DESC
            LIMIT {$perPage} OFFSET {$offset}
            SQL,
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /** @return array<int, array<string, mixed>> unpaid invoices past their due date */
    public function overdue(): array
    {
        return $this->db->select(
            "SELECT * FROM invoices WHERE status = 'unpaid' AND due_date < ?",
            [(new DateTimeImmutable())->format('Y-m-d')]
        );
    }

    /** @return array<int, array<string, mixed>> unpaid invoices due on or before today — auto-charge candidates */
    public function dueUnpaid(): array
    {
        return $this->db->select(
            "SELECT * FROM invoices WHERE status = 'unpaid' AND due_date <= ? ORDER BY due_date ASC, id ASC",
            [(new DateTimeImmutable())->format('Y-m-d')]
        );
    }

    public function markPaid(int $id): void
    {
        $this->db->update(
            'UPDATE invoices SET status = ?, paid_at = ?, updated_at = ? WHERE id = ?',
            ['paid', (new DateTimeImmutable())->format('Y-m-d H:i:s'), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markCancelled(int $id): void
    {
        $this->db->update(
            'UPDATE invoices SET status = ?, updated_at = ? WHERE id = ?',
            ['cancelled', (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markRefunded(int $id): void
    {
        $this->db->update(
            'UPDATE invoices SET status = ?, updated_at = ? WHERE id = ?',
            ['refunded', (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Dashboard tiles (R17) — bare COUNT/SUM aggregates rather than
     * fetching overdue()'s full row set just to count() it.
     */
    public function countOverdue(): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM invoices WHERE status = 'unpaid' AND due_date < ?",
            [(new DateTimeImmutable())->format('Y-m-d')]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function sumOverdue(): float
    {
        $row = $this->db->selectOne(
            "SELECT COALESCE(SUM(total), 0) AS total FROM invoices WHERE status = 'unpaid' AND due_date < ?",
            [(new DateTimeImmutable())->format('Y-m-d')]
        );

        return (float) ($row['total'] ?? 0);
    }

    /** Sum of invoices paid since the 1st of the current calendar month. */
    public function totalPaidThisMonth(): float
    {
        $row = $this->db->selectOne(
            "SELECT COALESCE(SUM(total), 0) AS total FROM invoices WHERE status = 'paid' AND paid_at >= ?",
            [(new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00')]
        );

        return (float) ($row['total'] ?? 0);
    }

    /**
     * All-time paid total per client, highest first — backs the R21
     * TopClientsWidget dashboard widget.
     *
     * @return array<int, array{client_id: int, first_name: string, last_name: string, email: string, total_paid: float}>
     */
    public function topClientsByRevenue(int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));

        $rows = $this->db->select(
            <<<SQL
            SELECT i.client_id, c.first_name, c.last_name, c.email, SUM(i.total) AS total_paid
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            WHERE i.status = 'paid'
            GROUP BY i.client_id, c.first_name, c.last_name, c.email
            ORDER BY total_paid DESC
            LIMIT {$limit}
            SQL
        );

        return array_map(static fn (array $row) => [
            'client_id' => (int) $row['client_id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'email' => (string) $row['email'],
            'total_paid' => (float) $row['total_paid'],
        ], $rows);
    }

    /**
     * Creates a real invoice directly from a flat set of line items — the
     * same shape BillableItemInvoicingJob and checkout already build by
     * hand, extracted here as a reusable path for R23's quote-acceptance
     * conversion (and any future caller) without touching either of those
     * existing call sites.
     *
     * @param array<int, array{description: string, amount: float}> $items
     */
    public function createFromItems(int $clientId, array $items, ?int $currencyId, float $currencyRate, ?int $orderId = null, int $dueInDays = 0): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $subtotal = round(array_sum(array_column($items, 'amount')), 2);
        $dueDate = (new DateTimeImmutable("+{$dueInDays} days"))->format('Y-m-d');

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $orderId, 'unpaid', $subtotal, 0.0, $subtotal, $currencyId, $currencyRate, $dueDate, $now, $now]
        );

        foreach ($items as $item) {
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, $item['description'], $item['amount']]
            );
        }

        return $invoiceId;
    }

    /**
     * Creates an invoice with an explicit status/total/paid_at rather than
     * computing them from items — for importing historical billing records
     * (R29) where the source of truth is a legacy system's own totals, not
     * a recalculation. Writes a single summary line item so the invoice
     * still displays sensibly (blank line items would look broken), rather
     * than widening createFromItems() to take an optional status override.
     */
    public function createHistorical(int $clientId, string $status, float $total, float $taxAmount, string $dueDate, ?string $paidAt): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $subtotal = round($total - $taxAmount, 2);

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, due_date, paid_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $status, $subtotal, $taxAmount, $total, $dueDate, $paidAt, $now, $now]
        );

        $this->db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, 'Imported invoice', $subtotal]
        );

        return $invoiceId;
    }
}
