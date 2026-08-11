<?php

declare(strict_types=1);

namespace CodeVault\Reports;

use CodeVault\Database;

/**
 * Core built-in admin reports (blueprint §4.3) — plain aggregation
 * queries against tables that already exist, not a pluggable module
 * (that's ReportModule, for third-party/custom reports). Scoped to the
 * reports with the clearest, least ambiguous SQL: income, tax liability,
 * aged debtors, product breakdown (initial-order revenue), and affiliate
 * payouts.
 *
 * ## Currency
 *
 * Every money figure here is grouped by the currency the document was
 * actually billed in and multiplied by that document's locked rate — the same
 * rule InvoiceRepository::sumByCurrency() applies for the dashboard.
 *
 * These queries used to be a bare `SUM(total)` across the whole table, which
 * is wrong twice over: it adds naira to dollars and reports the result under
 * whatever symbol the template hardcoded (always "$", even on an install whose
 * base currency is NGN), and it ignored currency_rate entirely so an invoice
 * locked at a rate counted as its unconverted stored figure.
 *
 * A NULL currency_id collapses onto the *client's* currency first (imported
 * rows and pre-locking batches store no lock even for NGN clients — see
 * InvoiceRepository::sumByCurrency), and only then onto the default currency
 * for clients that have no currency at all. Collapsing onto the default alone
 * used to label naira money with "$" and inflate the dollar totals.
 */
final class ReportRepository
{
    /**
     * Multiplier for a document's locked rate. NULLIF guards rows whose rate
     * was never set (legacy/imported), where a literal 0 would zero out the
     * whole currency's total. Rows with no locked currency are stored "as
     * billed" and are never re-converted (the same rule
     * CurrencyService::formatLocked applies), so their rate is forced to 1.
     */
    private const RATE = 'COALESCE(CASE WHEN %s.currency_id IS NULL THEN 1 ELSE NULLIF(%s.currency_rate, 0) END, 1)';

    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * The id of the default currency, the final fallback in the currency
     * COALESCE after the invoice's own lock and the client's currency.
     *
     * A default-currency invoice stores NULL (native) or the default's own id
     * (imported), and both have to group together — grouping on the raw column
     * split those apart and printed one currency as two totals on the same
     * line: "$59.11 USD | $67,388.37 USD".
     */
    private function defaultCurrencyId(): ?int
    {
        $row = $this->db->selectOne('SELECT id FROM currencies WHERE is_default = 1 LIMIT 1');

        return $row !== null ? (int) $row['id'] : null;
    }

    /** @return array<int, array{month: string, currency_id: ?int, total: float}> */
    public function incomeByMonth(int $year): array
    {
        $rate = sprintf(self::RATE, 'i', 'i');

        return $this->normalise($this->db->select(
            <<<SQL
            SELECT DATE_FORMAT(i.paid_at, '%Y-%m') AS month,
                   COALESCE(i.currency_id, c.currency_id, ?) AS currency_id,
                   SUM(i.total * {$rate}) AS total
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            WHERE i.status = 'paid' AND YEAR(i.paid_at) = ?
            GROUP BY DATE_FORMAT(i.paid_at, '%Y-%m'), COALESCE(i.currency_id, c.currency_id, ?)
            ORDER BY month
            SQL,
            [$this->defaultCurrencyId(), $year, $this->defaultCurrencyId()]
        ), 'total');
    }

    /**
     * Transactions carry no currency of their own — they are recorded in the
     * unit their invoice's `total` is stored in (see
     * PaymentCallbackController::toInvoiceCurrency), so the invoice is what
     * says which currency this money is.
     *
     * @return array<int, array{gateway_slug: string, currency_id: ?int, total: float}>
     */
    public function incomeByGateway(int $year): array
    {
        $rate = sprintf(self::RATE, 'i', 'i');

        return $this->normalise($this->db->select(
            <<<SQL
            SELECT t.gateway_slug,
                   COALESCE(i.currency_id, c.currency_id, ?) AS currency_id,
                   SUM(t.amount * {$rate}) AS total
            FROM transactions t
            JOIN invoices i ON i.id = t.invoice_id
            JOIN clients c ON c.id = i.client_id
            WHERE t.status = 'completed' AND YEAR(t.created_at) = ?
            GROUP BY t.gateway_slug, COALESCE(i.currency_id, c.currency_id, ?)
            ORDER BY total DESC
            SQL,
            [$this->defaultCurrencyId(), $year, $this->defaultCurrencyId()]
        ), 'total');
    }

    /** @return array<int, array{month: string, currency_id: ?int, tax_amount: float}> */
    public function taxLiabilityByMonth(int $year): array
    {
        $rate = sprintf(self::RATE, 'i', 'i');

        return $this->normalise($this->db->select(
            <<<SQL
            SELECT DATE_FORMAT(i.paid_at, '%Y-%m') AS month,
                   COALESCE(i.currency_id, c.currency_id, ?) AS currency_id,
                   SUM(i.tax_amount * {$rate}) AS tax_amount
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            WHERE i.status = 'paid' AND YEAR(i.paid_at) = ?
            GROUP BY DATE_FORMAT(i.paid_at, '%Y-%m'), COALESCE(i.currency_id, c.currency_id, ?)
            ORDER BY month
            SQL,
            [$this->defaultCurrencyId(), $year, $this->defaultCurrencyId()]
        ), 'tax_amount');
    }

    /**
     * Casts the driver's string columns and keeps currency_id nullable-int, so
     * callers never have to re-check types.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalise(array $rows, string $amountKey): array
    {
        return array_map(static function (array $row) use ($amountKey): array {
            $row['currency_id'] = $row['currency_id'] !== null ? (int) $row['currency_id'] : null;
            $row[$amountKey] = (float) $row[$amountKey];

            return $row;
        }, $rows);
    }

    /**
     * Unpaid invoices bucketed by days overdue (blueprint §4.3 "aged
     * debtors"). Bucketing happens in PHP rather than a SQL CASE so the
     * bucket boundaries stay easy to read/change in one place.
     *
     * @return array<string, array{label: string, invoices: array<int, array<string, mixed>>, total: float}>
     */
    public function agedDebtors(): array
    {
        $rows = $this->db->select(
            <<<'SQL'
            SELECT i.*, c.first_name, c.last_name, c.email AS client_email,
                   c.currency_id AS client_currency_id,
                   DATEDIFF(CURDATE(), i.due_date) AS days_overdue
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            WHERE i.status = 'unpaid'
            ORDER BY days_overdue DESC
            SQL
        );

        $defaultCurrencyId = $this->defaultCurrencyId();

        $buckets = [
            'current' => ['label' => 'Not yet due', 'invoices' => [], 'totals' => []],
            '1-30' => ['label' => '1-30 days overdue', 'invoices' => [], 'totals' => []],
            '31-60' => ['label' => '31-60 days overdue', 'invoices' => [], 'totals' => []],
            '61-90' => ['label' => '61-90 days overdue', 'invoices' => [], 'totals' => []],
            '90+' => ['label' => '90+ days overdue', 'invoices' => [], 'totals' => []],
        ];

        foreach ($rows as $row) {
            $days = (int) $row['days_overdue'];
            $key = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default => '90+',
            };

            // The amount as actually invoiced, which is what a debtor owes —
            // the stored figure times the invoice's own locked rate, exactly
            // what the invoice screen shows them.
            //
            // A NULL currency_id is "base currency" for a row locked via
            // lockColumns(), but it also covers invoices that never locked a
            // currency at all (imported rows, pre-locking batches) — lumping
            // those onto the default currency labels naira money with "$" and
            // inflates the dollar totals. Falling back to the client's
            // currency first (the same rule every other report uses here)
            // keeps each figure under the symbol it was actually billed in;
            // the default currency is only the final fallback for clients
            // with no currency at all.
            $rawId = $row['currency_id'];
            if ($rawId === null || $rawId === '') {
                $clientRawId = $row['client_currency_id'] ?? null;
                $clientCurrencyId = ($clientRawId === null || $clientRawId === '') ? null : (int) $clientRawId;
                $currencyId = $clientCurrencyId ?? $defaultCurrencyId;
            } else {
                $currencyId = (int) $rawId;
            }
            $lockedRate = (float) ($row['currency_rate'] ?? 1.0);
            $amount = round((float) $row['total'] * ($rawId !== null && $rawId !== '' && $lockedRate > 0 ? $lockedRate : 1.0), 2);

            $row['display_amount'] = $amount;
            // These rows come from `SELECT i.*`, so currency_id arrives as a
            // PDO string. Normalise it to ?int here so consumers get the same
            // shape every other method in this class returns.
            $row['currency_id'] = $currencyId;
            $buckets[$key]['invoices'][] = $row;

            // Bucket totals stay split by currency: adding naira to dollars
            // and printing one number under one symbol is what this report
            // used to do.
            $bucketKey = $currencyId === null ? 'base' : (string) $currencyId;
            $buckets[$key]['totals'][$bucketKey] ??= ['currency_id' => $currencyId, 'amount' => 0.0];
            $buckets[$key]['totals'][$bucketKey]['amount'] += $amount;
        }

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['totals'] = array_values($bucket['totals']);
        }

        return $buckets;
    }

    /**
     * Revenue by product from initial (order-time) purchases — renewal
     * revenue lives on services/invoices without a product_id join, so
     * this covers first-order revenue, not lifetime product revenue.
     *
     * @return array<int, array{product_name: string, quantity: int, revenue: float}>
     */
    public function productBreakdown(): array
    {
        $rate = sprintf(self::RATE, 'o', 'o');

        // Accepted orders only.
        //
        // This used to count 'fraud' and 'pending' as revenue too. A fraud
        // order is one staff flagged as fraudulent and never fulfilled, and a
        // pending order has not been accepted yet — booking either as revenue
        // overstates every product's takings, and the fraud case does so with
        // money that by definition was never collected.
        //
        // Currency falls back to the ordering client's currency for orders
        // with no lock, then to the default — same rule as the income report,
        // so naira orders never land under "$".
        return $this->normalise($this->db->select(
            <<<SQL
            SELECT oi.product_name, COALESCE(o.currency_id, c.currency_id, ?) AS currency_id,
                   SUM(oi.quantity) AS quantity,
                   SUM((oi.unit_price * oi.quantity + oi.setup_fee) * {$rate}) AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN clients c ON c.id = o.client_id
            WHERE o.status = 'active'
            GROUP BY oi.product_name, COALESCE(o.currency_id, c.currency_id, ?)
            ORDER BY revenue DESC
            SQL,
            [$this->defaultCurrencyId(), $this->defaultCurrencyId()]
        ), 'revenue');
    }

    /**
     * Commissions have no currency column; like transactions they are derived
     * from an invoice, so the invoice says which currency they are in.
     *
     * @return array<int, array{code: string, client_name: string, currency_id: ?int, paid_total: float, pending_total: float}>
     */
    public function affiliatePayouts(): array
    {
        $rate = sprintf(self::RATE, 'i', 'i');

        $rows = $this->db->select(
            <<<SQL
            SELECT
                a.code,
                CONCAT(c.first_name, ' ', c.last_name) AS client_name,
                COALESCE(i.currency_id, ic.currency_id, ?) AS currency_id,
                COALESCE(SUM(CASE WHEN ac.status = 'paid' THEN ac.amount * {$rate} ELSE 0 END), 0) AS paid_total,
                COALESCE(SUM(CASE WHEN ac.status IN ('pending', 'requested') THEN ac.amount * {$rate} ELSE 0 END), 0) AS pending_total
            FROM affiliates a
            JOIN clients c ON c.id = a.client_id
            LEFT JOIN affiliate_commissions ac ON ac.affiliate_id = a.id
            LEFT JOIN invoices i ON i.id = ac.invoice_id
            LEFT JOIN clients ic ON ic.id = i.client_id
            GROUP BY a.id, a.code, client_name, COALESCE(i.currency_id, ic.currency_id, ?)
            ORDER BY paid_total DESC
            SQL,
            [$this->defaultCurrencyId(), $this->defaultCurrencyId()]
        );

        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'client_name' => (string) $row['client_name'],
            'currency_id' => $row['currency_id'] !== null ? (int) $row['currency_id'] : null,
            'paid_total' => (float) $row['paid_total'],
            'pending_total' => (float) $row['pending_total'],
        ], $rows);
    }
}
