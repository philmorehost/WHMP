<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Append-only credit ledger (blueprint §4.4 "account credit"). Balance is
 * always SUM(amount) — never a stored column — so it can't drift.
 */
final class ClientCreditRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function balance(int $clientId): float
    {
        $row = $this->db->selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS balance FROM client_credit_ledger WHERE client_id = ?',
            [$clientId]
        );

        return (float) ($row['balance'] ?? 0);
    }

    public function add(int $clientId, float $amount, string $reason, ?int $invoiceId = null, ?int $adminId = null, ?int $creditNoteId = null): int
    {
        return (int) $this->db->insert(
            'INSERT INTO client_credit_ledger (client_id, amount, reason, invoice_id, credit_note_id, admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $amount, $reason, $invoiceId, $creditNoteId, $adminId, (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select('SELECT * FROM client_credit_ledger WHERE client_id = ? ORDER BY id DESC', [$clientId]);
    }
}
