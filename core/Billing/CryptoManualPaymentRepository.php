<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;

/**
 * manual_crypto_payments rows — one client payment attempt per row: the
 * USD figure locked at submit time, the wallet it was to be sent to, the
 * uploaded proof, and the admin decision. Nothing is ever deleted; a
 * rejection invites a new submission rather than overwriting history.
 */
final class CryptoManualPaymentRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function create(array $fields): int
    {
        return (int) $this->db->insert(
            'INSERT INTO manual_crypto_payments
                (invoice_id, client_id, reference, amount_usd, usd_currency_id, usd_rate, wallet_address, txid, proof_path, proof_name, proof_mime, proof_size, note, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $fields['invoice_id'],
                (int) $fields['client_id'],
                (string) $fields['reference'],
                (float) $fields['amount_usd'],
                $fields['usd_currency_id'] !== null ? (int) $fields['usd_currency_id'] : null,
                (float) $fields['usd_rate'],
                (string) $fields['wallet_address'],
                $fields['txid'] !== null && trim((string) $fields['txid']) !== '' ? trim((string) $fields['txid']) : null,
                (string) $fields['proof_path'],
                (string) $fields['proof_name'],
                (string) $fields['proof_mime'],
                (int) $fields['proof_size'],
                $fields['note'] !== null && trim((string) $fields['note']) !== '' ? trim((string) $fields['note']) : null,
                'pending',
                (string) $fields['created_at'],
                (string) $fields['created_at'],
            ]
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM manual_crypto_payments WHERE id = ?', [$id]);
    }

    /** The submission an admin still has to decide — one at a time per invoice. */
    public function pendingForInvoice(int $invoiceId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM manual_crypto_payments WHERE invoice_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$invoiceId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forInvoice(int $invoiceId, int $limit = 5): array
    {
        return $this->db->select(
            'SELECT * FROM manual_crypto_payments WHERE invoice_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$invoiceId]
        );
    }

    /**
     * The admin review queue: everything still pending first, then the most
     * recent decisions for context. Client and invoice joined for display.
     *
     * @return array{pending: array<int, array<string, mixed>>, reviewed: array<int, array<string, mixed>>}
     */
    public function forReview(int $reviewedLimit = 50): array
    {
        $select = 'SELECT p.*, c.email AS client_email, c.first_name, c.last_name, i.total AS invoice_total, i.status AS invoice_status, i.currency_id AS invoice_currency_id, i.currency_rate AS invoice_currency_rate
                   FROM manual_crypto_payments p
                   INNER JOIN clients c ON c.id = p.client_id
                   INNER JOIN invoices i ON i.id = p.invoice_id';

        $pending = $this->db->select("{$select} WHERE p.status = 'pending' ORDER BY p.id ASC");
        $reviewed = $this->db->select("{$select} WHERE p.status <> 'pending' ORDER BY p.reviewed_at DESC, p.id DESC LIMIT " . max(1, $reviewedLimit));

        return ['pending' => $pending, 'reviewed' => $reviewed];
    }

    public function markApproved(int $id, int $adminId, string $note, int $transactionId): int
    {
        return $this->db->update(
            "UPDATE manual_crypto_payments SET status = 'approved', admin_id = ?, admin_note = ?, transaction_id = ?, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$adminId, $note !== '' ? $note : null, $transactionId > 0 ? $transactionId : null, $id]
        );
    }

    public function markRejected(int $id, int $adminId, string $note): int
    {
        return $this->db->update(
            "UPDATE manual_crypto_payments SET status = 'rejected', admin_id = ?, admin_note = ?, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$adminId, $note, $id]
        );
    }

    public function pendingCount(): int
    {
        $row = $this->db->selectOne("SELECT COUNT(*) AS c FROM manual_crypto_payments WHERE status = 'pending'");

        return (int) ($row['c'] ?? 0);
    }
}
