<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

final class BillableItemRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM billable_items WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT bi.*, c.first_name, c.last_name, c.email AS client_email
            FROM billable_items bi
            JOIN clients c ON c.id = bi.client_id
            ORDER BY bi.id DESC
            SQL
        );
    }

    /** @return array<int, array<string, mixed>> billable items not yet invoiced */
    public function uninvoiced(): array
    {
        return $this->db->select('SELECT * FROM billable_items WHERE invoice_id IS NULL');
    }

    public function create(int $clientId, string $description, float $amount, ?string $sourceType = null, ?int $sourceId = null): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO billable_items (client_id, description, amount, source_type, source_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $description, $amount, $sourceType, $sourceId, $now, $now]
        );
    }

    public function markInvoiced(int $id, int $invoiceId): void
    {
        $this->db->update(
            'UPDATE billable_items SET invoice_id = ?, updated_at = ? WHERE id = ?',
            [$invoiceId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }
}
