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
        return $this->db->select('SELECT * FROM quotes WHERE client_id = ? ORDER BY id DESC', [$clientId]);
    }

    /** @return array<int, array<string, mixed>> newest first, joined with the client's email for the admin list */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT q.*, c.email AS client_email, c.first_name, c.last_name
            FROM quotes q
            JOIN clients c ON c.id = q.client_id
            ORDER BY q.id DESC
            SQL
        );
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
