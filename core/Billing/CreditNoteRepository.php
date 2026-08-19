<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

final class CreditNoteRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * @param array<int, array{description: string, amount: float}> $items
     */
    public function create(int $clientId, ?int $invoiceId, string $reason, float $total, ?int $currencyId, float $currencyRate, ?int $adminId, array $items): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $id = (int) $this->db->insert(
            'INSERT INTO credit_notes (client_id, invoice_id, reason, total, currency_id, currency_rate, created_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $invoiceId, $reason, $total, $currencyId, $currencyRate, $adminId, $now, $now]
        );

        foreach ($items as $item) {
            $this->db->insert(
                'INSERT INTO credit_note_items (credit_note_id, description, amount) VALUES (?, ?, ?)',
                [$id, $item['description'], $item['amount']]
            );
        }

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM credit_notes WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function items(int $creditNoteId): array
    {
        return $this->db->select('SELECT * FROM credit_note_items WHERE credit_note_id = ?', [$creditNoteId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select(
            <<<SQL
            SELECT cn.*, cu.symbol AS currency_symbol
            FROM credit_notes cn
            JOIN clients c ON c.id = cn.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            WHERE cn.client_id = ? 
            ORDER BY cn.id DESC
            SQL,
            [$clientId]
        );
    }

    /** @return array<int, array<string, mixed>> newest first, joined with the client's email for the admin list */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT cn.*, c.email AS client_email, c.first_name, c.last_name, cu.symbol AS currency_symbol
            FROM credit_notes cn
            JOIN clients c ON c.id = cn.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            ORDER BY cn.id DESC
            SQL
        );
    }

    /**
     * Paginated, per-column-filterable credit-note list for the admin page.
     *
     * @param array<string, string> $filters sanitised `filters[]` bag (see Table\TableFilters)
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(int $page = 1, int $perPage = 15, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        [$filterWhere, $bindings] = \CodeVault\Table\TableFilters::where($filters, [
            'id'      => ['cn.id', 'number'],
            'client'  => [['c.first_name', 'c.last_name', 'c.email'], 'like'],
            'reason'  => ['cn.reason', 'like'],
            'total'   => ['cn.total', 'number'],
            'invoice' => ['cn.invoice_id', 'number'],
            'issued'  => ['cn.created_at', 'like'],
        ]);

        $where = $filterWhere === '' ? '' : 'WHERE ' . $filterWhere;

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS c FROM credit_notes cn JOIN clients c ON c.id = cn.client_id {$where}",
            $bindings
        )['c'] ?? 0);

        $data = $this->db->select(
            <<<SQL
            SELECT cn.*, c.email AS client_email, c.first_name, c.last_name, cu.symbol AS currency_symbol
            FROM credit_notes cn
            JOIN clients c ON c.id = cn.client_id
            LEFT JOIN currencies cu ON cu.id = COALESCE(c.currency_id, (SELECT id FROM currencies WHERE is_default = 1 LIMIT 1))
            {$where}
            ORDER BY cn.id DESC
            LIMIT {$perPage} OFFSET {$offset}
            SQL,
            $bindings
        );

        return ['data' => $data, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }
}
