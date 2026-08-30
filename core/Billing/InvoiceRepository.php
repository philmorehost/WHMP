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

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * The invoice an order raised (orders have at most one invoice).
     *
     * @return array<string, mixed>|null
     */
    public function findByOrder(int $orderId): ?array
    {
        return $this->db->selectOne('SELECT * FROM invoices WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
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
    public function paginateForClient(int $clientId, int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $total = (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM invoices WHERE client_id = ?", [$clientId])['c'] ?? 0);

        $data = $this->db->select(
            "SELECT * FROM invoices WHERE client_id = ? ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            [$clientId]
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    /**
     * @param array<string, string> $filters sanitised `filters[]` bag (see Table\TableFilters)
     * @param array{column: string, dir: string}|null $sort
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(?string $status = null, int $page = 1, int $perPage = 20, array $filters = [], ?array $sort = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $bindings = [];

        if ($status !== null) {
            $conditions[] = 'i.status = ?';
            $bindings[] = $status;
        }

        [$filterWhere, $filterBindings] = \CodeVault\Table\TableFilters::where($filters, [
            'id'       => ['i.id', 'number'],
            'client'   => [['c.first_name', 'c.last_name', 'c.email'], 'like'],
            'total'    => ['i.total', 'number'],
            'due_date' => ['i.due_date', 'like'],
            'status'   => ['i.status', 'eq'],
        ]);

        if ($filterWhere !== '') {
            $conditions[] = $filterWhere;
            $bindings = array_merge($bindings, $filterBindings);
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $sortable = [
            'id'       => 'i.id',
            'client'   => 'c.last_name',
            'total'    => 'i.total',
            'due_date' => 'i.due_date',
            'status'   => 'i.status',
        ];
        $orderBy = \CodeVault\Table\TableFilters::orderBy($sortable, $sort);
        if ($orderBy === '') {
            $orderBy = 'ORDER BY i.id DESC';
        }

        $total = (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM invoices i JOIN clients c ON c.id = i.client_id {$where}", $bindings)['c'] ?? 0);

        // currency_id IS NULL covers two different histories: an invoice
        // deliberately locked to the base currency (CurrencyService::
        // lockColumns stores NULL for the default), and one that never locked
        // at all — imported from WHMCS, or written by a path that skipped the
        // currency columns. Nothing in the row distinguishes them.
        //
        // This previously resolved NULL to the system default, which is right
        // for the first case and wrong for the second: a client billed in
        // naira saw their imported invoices labelled with the default symbol.
        // It now falls back to the client's own currency first, matching what
        // the client sees on their own invoice list — the same invoice
        // reading "₦7,501.50" to the client and "$7,501.50" to the admin was
        // worse than either rule on its own. currency_rate is 1.0 on these
        // rows, so the amount is displayed as stored, never re-converted.
        $data = $this->db->select(
            <<<SQL
            SELECT i.*, c.email AS client_email, c.first_name, c.last_name, curr.code AS currency_code, curr.symbol AS currency_symbol
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            LEFT JOIN currencies curr ON curr.id = COALESCE(i.currency_id, c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            {$where}
            {$orderBy}
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

    public function markPaid(int $id): int
    {
        return $this->db->update(
            "UPDATE invoices SET status = ?, paid_at = ?, updated_at = ? WHERE id = ? AND status = 'unpaid'",
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

    public function cancelUnpaidForService(int $serviceId): void
    {
        $this->db->update(
            "UPDATE invoices SET status = 'cancelled', updated_at = ? WHERE service_id = ? AND status = 'unpaid'",
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $serviceId]
        );
    }

    /**
     * Cancels many invoices at once, skipping any that aren't unpaid.
     *
     * The status guard is in the WHERE clause rather than a pre-check: a paid
     * or already-refunded invoice must never be flipped to cancelled by a bulk
     * action, and doing it in SQL means a payment landing mid-request can't
     * slip through a race between checking and updating.
     *
     * @param array<int, int> $ids
     * @return int invoices actually cancelled
     */
    public function cancelManyUnpaid(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return $this->db->update(
            "UPDATE invoices SET status = 'cancelled', updated_at = ? WHERE status = 'unpaid' AND id IN ({$placeholders})",
            array_merge([(new DateTimeImmutable())->format('Y-m-d H:i:s')], $ids)
        );
    }

    /**
     * How many unpaid invoices have nothing to collect.
     *
     * Shown to the admin before the bulk action runs, so the number of rows
     * about to change is visible rather than implied.
     */
    public function countZeroValueUnpaid(): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM invoices WHERE status = 'unpaid' AND total <= 0.004"
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Settles every unpaid invoice with a zero total.
     *
     * These come from imports and from orders fully covered by credit or a
     * 100% discount. They can never be paid — there is nothing to charge — so
     * they sit in the client's unpaid list forever, inflating the "unpaid
     * invoices" count and burying the invoices that do need paying.
     *
     * `total <= 0.004` rather than `= 0` because total is DECIMAL(18,6): a row
     * carrying 0.000001 from a rounding artefact is still nothing to collect,
     * and a strict equality would silently skip it. The threshold stays well
     * under half a cent so no genuinely payable invoice is caught.
     *
     * Deliberately does NOT go through PaymentService: there is no money to
     * record, so writing a zero-value transaction would pollute the ledger,
     * and firing InvoicePaid would trigger renewals, affiliate commissions and
     * service reactivations for invoices that were never really paid. This is
     * a data-cleanup, not a payment.
     *
     * @return int invoices marked paid
     */
    public function markZeroValueUnpaidAsPaid(): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->db->update(
            "UPDATE invoices SET status = 'paid', paid_at = ?, updated_at = ?
             WHERE status = 'unpaid' AND total <= 0.004",
            [$now, $now]
        );
    }

    /**
     * Cancels unpaid invoices whose due date passed more than $days ago.
     *
     * The service guard is the important part. OverdueSuspensionJob and
     * ServiceTerminationJob decide a service is in arrears by looking for an
     * unpaid invoice against it — so cancelling those invoices would make
     * every delinquent service look settled and quietly stop it ever being
     * suspended or terminated. Only invoices with no service, or whose service
     * is already cancelled/terminated, are swept.
     *
     * Rows carrying a zero/invalid due date (0000-00-00, common in imported
     * data) sort before any cutoff and are therefore included — they are
     * stale by definition.
     *
     * @return int invoices cancelled
     */
    public function cancelStaleUnpaid(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d');
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->db->update(
            "UPDATE invoices i
             LEFT JOIN services s ON s.id = i.service_id
             SET i.status = 'cancelled', i.updated_at = ?
             WHERE i.status = 'unpaid'
               AND i.due_date < ?
               AND (i.service_id IS NULL OR s.id IS NULL OR s.status IN ('cancelled', 'terminated'))",
            [$now, $cutoff]
        );
    }

    /** Preview count for the same rule, so the admin can see the impact first. */
    public function countStaleUnpaid(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d');

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c
             FROM invoices i
             LEFT JOIN services s ON s.id = i.service_id
             WHERE i.status = 'unpaid'
               AND i.due_date < ?
               AND (i.service_id IS NULL OR s.id IS NULL OR s.status IN ('cancelled', 'terminated'))",
            [$cutoff]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Replaces an invoice's line items and recalculates its totals.
     *
     * The amounts entered are in whatever currency the invoice is already
     * denominated in — currency_id and currency_rate are deliberately left
     * untouched, so editing a naira invoice can't quietly re-denominate it or
     * re-apply a conversion rate to the new figures.
     *
     * tax_amount is preserved as a proportion of the new subtotal rather than
     * carried over as a flat figure: halving the line items should halve the
     * tax, not leave the old tax sitting on a smaller invoice.
     *
     * @param array<int, array{description: string, amount: float}> $items
     */
    public function replaceItems(int $invoiceId, array $items, ?string $dueDate = null): void
    {
        $existing = $this->find($invoiceId);

        if ($existing === null) {
            return;
        }

        $oldSubtotal = (float) $existing['subtotal'];
        $oldTax = (float) $existing['tax_amount'];
        $taxRate = $oldSubtotal > 0 ? $oldTax / $oldSubtotal : 0.0;

        $subtotal = round(array_sum(array_column($items, 'amount')), 2);
        $tax = round($subtotal * $taxRate, 2);
        $discount = (float) ($existing['discount_amount'] ?? 0);
        $total = round($subtotal + $tax - $discount, 2);

        $this->db->delete('DELETE FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);

        foreach ($items as $item) {
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, $item['description'], $item['amount']]
            );
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($dueDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) === 1) {
            $this->db->update(
                'UPDATE invoices SET subtotal = ?, tax_amount = ?, total = ?, due_date = ?, updated_at = ? WHERE id = ?',
                [$subtotal, $tax, max(0.0, $total), $dueDate, $now, $invoiceId]
            );

            return;
        }

        $this->db->update(
            'UPDATE invoices SET subtotal = ?, tax_amount = ?, total = ?, updated_at = ? WHERE id = ?',
            [$subtotal, $tax, max(0.0, $total), $now, $invoiceId]
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
    /**
     * Paid-this-month and overdue totals split by the currency each invoice
     * was actually billed in.
     *
     * A single SUM(total) across the table is meaningless once more than one
     * currency is in play: it adds naira to dollars and reports the result
     * under whichever symbol the template happens to hardcode. It also ignores
     * currency_rate, so an invoice locked at 1500 counted as its base figure.
     *
     * Grouping by effective currency and multiplying by the locked rate gives
     * the amount as actually invoiced, per currency, which is the only figure
     * that can be honestly displayed or added up. Invoices with no locked
     * currency fall back to their owner's currency (see sumByCurrency()) so
     * naira-billed rows never leak into the dollar bucket.
     *
     * @return array<int, array{currency_id: ?int, amount: float, invoices: int}>
     */
    public function paidThisMonthByCurrency(): array
    {
        return $this->sumByCurrency(
            "i.status = 'paid' AND i.paid_at >= ?",
            [(new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00')]
        );
    }

    /** @return array<int, array{currency_id: ?int, amount: float, invoices: int}> */
    public function overdueByCurrency(): array
    {
        return $this->sumByCurrency(
            "i.status = 'unpaid' AND i.due_date < ?",
            [(new DateTimeImmutable())->format('Y-m-d')]
        );
    }

    /**
     * @param array<int, mixed> $bindings
     * @return array<int, array{currency_id: ?int, amount: float, invoices: int}>
     */
    private function sumByCurrency(string $where, array $bindings): array
    {
        // NULLIF guards rows whose rate was never set (legacy/imported), where
        // a literal 0 would zero the whole currency's total.
        //
        // Grouping is by the *effective* currency, not the raw column. A NULL
        // currency_id is "base currency" for a row locked via lockColumns(),
        // but it also covers invoices that never locked a currency at all —
        // imported rows, and batches written before currency locking (the
        // recurring-billing job stores NGN-client invoices as NULL). Lumping
        // all of those into one bucket labels naira money with the default
        // "$" symbol and sums it into the dollar figure on the dashboard.
        // Falling back to the client's currency (the same rule paginate() and
        // formatDocument() already use) keeps each amount under the symbol it
        // was actually billed in.
        //
        // The rate is applied only to rows that locked a non-default currency
        // (lockColumns(): total is stored in the base currency, × rate
        // re-expresses it). NULL and rate-1.0 rows are stored "as billed" and
        // are never re-converted.
        $rows = $this->db->select(
            "SELECT COALESCE(i.currency_id, c.currency_id) AS currency_id,
                    COALESCE(SUM(
                        i.total * COALESCE(
                            CASE WHEN i.currency_id IS NULL THEN 1 ELSE NULLIF(i.currency_rate, 0) END,
                            1
                        )
                    ), 0) AS amount,
                    COUNT(*) AS invoices
             FROM invoices i
             JOIN clients c ON c.id = i.client_id
             WHERE {$where}
             GROUP BY COALESCE(i.currency_id, c.currency_id)
             ORDER BY amount DESC",
            $bindings
        );

        return array_map(static fn (array $row): array => [
            'currency_id' => $row['currency_id'] !== null ? (int) $row['currency_id'] : null,
            'amount' => (float) $row['amount'],
            'invoices' => (int) $row['invoices'],
        ], $rows);
    }

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
    public function createFromItems(int $clientId, array $items, ?int $currencyId, float $currencyRate, ?int $orderId = null, int $dueInDays = 0, ?int $recurringInvoiceId = null, ?string $dueDate = null): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $subtotal = round(array_sum(array_column($items, 'amount')), 2);
        // An explicit due date (used by recurring-invoice generation, where
        // the invoice is due the day its cycle comes due) wins over the
        // today + dueInDays default.
        $dueDate ??= (new DateTimeImmutable("+{$dueInDays} days"))->format('Y-m-d');

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, order_id, recurring_invoice_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $orderId, $recurringInvoiceId, 'unpaid', $subtotal, 0.0, $subtotal, $currencyId, $currencyRate, $dueDate, $now, $now]
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
