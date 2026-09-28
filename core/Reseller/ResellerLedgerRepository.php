<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;

/**
 * Reads and writes the reseller's running account.
 *
 * Deliberately dumb SQL, exactly like ResellerCostRepository: this class does not
 * convert anything between currencies, because conversion is a money rule and
 * money rules live in ResellerLedgerService. Every amount here is a BASE-currency
 * figure that some other layer decided on.
 *
 * The balance is NEVER stored. It is SUM(amount) every time it is asked for — a
 * figure that is both stored and derived will eventually disagree with itself,
 * and the one thing this account has to be able to answer is "why is the balance
 * what it is".
 *
 * It also owns the two fact-lookups the service needs (which store order a paid
 * invoice belongs to, and what a cost invoice says), because "which order is this
 * invoice for" is a question about tables this class already knows, and answering
 * it here keeps the service free of SQL.
 */
final class ResellerLedgerRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Post one entry. Returns its id.
     *
     * The caller is expected to have decided the amount; this method only writes
     * it. A duplicate (same kind and order_id) raises the unique-key violation
     * rather than being swallowed, because a second post for the same order is a
     * bug in the caller, not a condition to tolerate silently — but callers that
     * can legitimately race should ask hasEntryForOrder() first.
     *
     * @param array<string, mixed> $entry
     */
    public function append(array $entry): int
    {
        return (int) $this->db->insert(
            'INSERT INTO reseller_ledger
                (reseller_id, client_id, kind, amount, withdrawable_at, order_id, invoice_id, payout_id, description, admin_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $entry['reseller_id'],
                $entry['client_id'] === null ? null : (int) $entry['client_id'],
                (string) $entry['kind'],
                (float) $entry['amount'],
                $entry['withdrawable_at'] ?? null,
                $entry['order_id'] === null ? null : (int) $entry['order_id'],
                $entry['invoice_id'] === null ? null : (int) $entry['invoice_id'],
                $entry['payout_id'] === null ? null : (int) $entry['payout_id'],
                $entry['description'] ?? null,
                $entry['admin_id'] === null ? null : (int) $entry['admin_id'],
                (string) $entry['created_at'],
            ]
        );
    }

    /**
     * Whether this order has already produced an entry of this kind.
     *
     * The unique key is the real guarantee; this is the cheap question a caller
     * asks first so the common re-fire is a no-op instead of an exception.
     */
    public function hasEntryForOrder(int $orderId, string $kind): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM reseller_ledger WHERE order_id = ? AND kind = ? LIMIT 1',
            [$orderId, $kind]
        ) !== null;
    }

    /**
     * The whole account, in the base currency. Not filtered by anything — a
     * negative balance is a real state (they priced below our cost) and must be
     * returned rather than clamped.
     */
    public function balance(int $resellerId): float
    {
        return round((float) ($this->db->selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM reseller_ledger WHERE reseller_id = ?',
            [$resellerId]
        )['total'] ?? 0.0), 2);
    }

    /**
     * The part of the balance a payout may actually draw on.
     *
     * A receipt inside its holding period still counts toward balance() — the
     * money is there and the reseller should see it — but not toward this. Debits
     * carry a NULL withdrawable_at and so always count, which is what stops a
     * pending receipt from shielding a cost.
     */
    public function withdrawableBalance(int $resellerId, string $asOf): float
    {
        return round((float) ($this->db->selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS total
             FROM reseller_ledger
             WHERE reseller_id = ? AND (withdrawable_at IS NULL OR withdrawable_at <= ?)',
            [$resellerId, $asOf]
        )['total'] ?? 0.0), 2);
    }

    /**
     * The entries behind the balance, newest first, for the statement view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entries(int $resellerId, int $limit = 200): array
    {
        return $this->db->select(
            'SELECT id, kind, amount, withdrawable_at, order_id, invoice_id, payout_id, description, created_at
             FROM reseller_ledger
             WHERE reseller_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, $limit),
            [$resellerId]
        );
    }

    /**
     * What the account is made of, per kind — the "why" behind the number.
     *
     * @return array<string, array<string, mixed>>
     */
    public function totalsByKind(int $resellerId): array
    {
        $rows = $this->db->select(
            'SELECT kind, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS entry_count
             FROM reseller_ledger
             WHERE reseller_id = ?
             GROUP BY kind',
            [$resellerId]
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['kind']] = [
                'total' => round((float) $row['total'], 2),
                'entry_count' => (int) $row['entry_count'],
            ];
        }

        return $totals;
    }

    /**
     * Balance and withdrawable amount for every store, in one pass.
     *
     * The report needs all stores at once; asking per store would be a query per
     * row of the page. Grouped in SQL, never summed across currencies in PHP
     * because the unit is already fixed to base.
     *
     * @return array<int, array<string, mixed>>
     */
    public function balancesByStore(string $asOf): array
    {
        return $this->db->select(
            'SELECT reseller_id,
                    COALESCE(SUM(amount), 0) AS balance,
                    COALESCE(SUM(CASE WHEN withdrawable_at IS NULL OR withdrawable_at <= ? THEN amount ELSE 0 END), 0) AS withdrawable,
                    COUNT(*) AS entry_count
             FROM reseller_ledger
             GROUP BY reseller_id',
            [$asOf]
        );
    }

    /**
     * The store order a paid invoice belongs to, with the money facts.
     *
     * One query answers both halves of the question — "is this a store order's
     * invoice at all" and "what did it charge" — so there is no window in which a
     * caller has half the answer. Returns null for any invoice that is not a
     * store order's, which is the common case: most invoices are ours.
     *
     * @return array<string, mixed>|null
     */
    public function storeOrderForInvoice(int $invoiceId): ?array
    {
        return $this->db->selectOne(
            'SELECT o.id AS order_id,
                    o.reseller_id,
                    r.client_id AS reseller_client_id,
                    i.id AS invoice_id,
                    i.status,
                    i.total,
                    i.currency_id,
                    i.currency_rate
             FROM invoices i
             JOIN orders o ON o.id = i.order_id
             JOIN resellers r ON r.id = o.reseller_id
             WHERE i.id = ?
               AND o.reseller_id IS NOT NULL
             LIMIT 1',
            [$invoiceId]
        );
    }

    /**
     * Whether this cost invoice has already been posted to the account.
     *
     * The (kind, invoice_id) unique key is the guarantee; this is the cheap
     * question asked first so a re-fire is a no-op rather than an exception.
     */
    public function hasCostEntryForInvoice(int $invoiceId): bool
    {
        return $this->db->selectOne(
            "SELECT id FROM reseller_ledger WHERE invoice_id = ? AND kind = 'cost_invoice' LIMIT 1",
            [$invoiceId]
        ) !== null;
    }

    /**
     * The store a cost invoice was billed to, found by following the orders it
     * billed — the same link ResellerCostRepository uses, so there is exactly one
     * definition of "what a cost invoice is".
     *
     * Returns null when the orders do NOT all belong to one store, rather than
     * picking the first: posting a mixed invoice to one store's account would
     * debit the wrong reseller, and that is worse than posting nothing. Phase 4
     * raises one invoice per store, so ambiguity means something already went
     * wrong and must be looked at.
     */
    public function resellerForCostInvoice(int $invoiceId): ?int
    {
        $row = $this->db->selectOne(
            'SELECT MIN(reseller_id) AS reseller_id, COUNT(DISTINCT reseller_id) AS store_count
             FROM orders
             WHERE reseller_cost_invoice_id = ? AND reseller_id IS NOT NULL',
            [$invoiceId]
        );

        if ($row === null || (int) $row['store_count'] !== 1) {
            return null;
        }

        return (int) $row['reseller_id'];
    }

    /**
     * A cost invoice's own figures, for the debit side.
     *
     * Read from the invoice rather than from the orders it billed: the invoice is
     * the document we actually sent, and if the two ever disagree the document is
     * what the reseller has been told they owe.
     *
     * @return array<string, mixed>|null
     */
    public function invoiceFacts(int $invoiceId): ?array
    {
        return $this->db->selectOne(
            'SELECT id AS invoice_id, client_id, total, currency_id, currency_rate
             FROM invoices
             WHERE id = ?
             LIMIT 1',
            [$invoiceId]
        );
    }
}
