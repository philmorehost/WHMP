<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

final class QuoteRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * @param array<int, array{description: string, amount: float}> $items
     */
    public function create(int $clientId, string $subject, ?string $validUntil, float $total, ?int $currencyId, float $currencyRate, ?int $adminId, array $items): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $id = (int) $this->db->insert(
            'INSERT INTO quotes (client_id, subject, status, valid_until, total, currency_id, currency_rate, created_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $subject, 'draft', $validUntil, $total, $currencyId, $currencyRate, $adminId, $now, $now]
        );

        foreach ($items as $item) {
            $this->db->insert(
                'INSERT INTO quote_items (quote_id, description, amount) VALUES (?, ?, ?)',
                [$id, $item['description'], $item['amount']]
            );
        }

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM quotes WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function items(int $quoteId): array
    {
        return $this->db->select('SELECT * FROM quote_items WHERE quote_id = ?', [$quoteId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select(
            <<<SQL
            SELECT q.*, cu.symbol AS currency_symbol
            FROM quotes q
            JOIN clients c ON c.id = q.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            WHERE q.client_id = ? 
            ORDER BY q.id DESC
            SQL,
            [$clientId]
        );
    }

    /** @return array<int, array<string, mixed>> newest first, joined with the client's email for the admin list */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT q.*, c.email AS client_email, c.first_name, c.last_name, cu.symbol AS currency_symbol
            FROM quotes q
            JOIN clients c ON c.id = q.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            ORDER BY q.id DESC
            SQL
        );
    }

    /**
     * Paginated, per-column-filterable quote list for the admin Quotes page.
     *
     * @param array<string, string> $filters sanitised `filters[]` bag (see Table\TableFilters)
     * @param array{column: string, dir: string}|null $sort
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(int $page = 1, int $perPage = 15, array $filters = [], ?array $sort = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        [$filterWhere, $bindings] = \CodeVault\Table\TableFilters::where($filters, [
            'id'           => ['q.id', 'number'],
            'client'       => [['c.first_name', 'c.last_name', 'c.email'], 'like'],
            'subject'      => ['q.subject', 'like'],
            'total'        => ['q.total', 'number'],
            'status'       => ['q.status', 'eq'],
            'valid_until'  => ['q.valid_until', 'like'],
        ]);

        $where = $filterWhere === '' ? '' : 'WHERE ' . $filterWhere;

        $sortable = [
            'id'          => 'q.id',
            'client'      => 'c.last_name',
            'subject'     => 'q.subject',
            'total'       => 'q.total',
            'status'      => 'q.status',
            'valid_until' => 'q.valid_until',
        ];
        $orderBy = \CodeVault\Table\TableFilters::orderBy($sortable, $sort);
        if ($orderBy === '') {
            $orderBy = 'ORDER BY q.id DESC';
        }

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS c FROM quotes q JOIN clients c ON c.id = q.client_id {$where}",
            $bindings
        )['c'] ?? 0);

        $data = $this->db->select(
            <<<SQL
            SELECT q.*, c.email AS client_email, c.first_name, c.last_name, cu.symbol AS currency_symbol
            FROM quotes q
            JOIN clients c ON c.id = q.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            {$where}
            {$orderBy}
            LIMIT {$perPage} OFFSET {$offset}
            SQL,
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /** Draft-only — a quote that's been sent is a real document a client may be viewing, never silently removed. */
    public function delete(int $id): void
    {
        $this->db->delete("DELETE FROM quotes WHERE id = ? AND status = 'draft'", [$id]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->db->update(
            'UPDATE quotes SET status = ?, updated_at = ? WHERE id = ?',
            [$status, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markConverted(int $id, int $invoiceId): void
    {
        $this->db->update(
            "UPDATE quotes SET status = 'accepted', invoice_id = ?, updated_at = ? WHERE id = ?",
            [$invoiceId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** @return array<int, array<string, mixed>> draft/sent quotes whose valid_until has passed — QuoteExpiryJob */
    public function overdue(): array
    {
        return $this->db->select(
            "SELECT * FROM quotes WHERE status IN ('draft', 'sent') AND valid_until IS NOT NULL AND valid_until < ?",
            [(new DateTimeImmutable())->format('Y-m-d')]
        );
    }
}
