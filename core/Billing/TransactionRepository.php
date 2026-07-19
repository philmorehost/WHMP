<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

final class TransactionRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function create(int $invoiceId, string $gatewaySlug, float $amount, string $status = 'completed', ?string $gatewayTransactionId = null): int
    {
        return (int) $this->db->insert(
            'INSERT INTO transactions (invoice_id, gateway_slug, amount, status, gateway_transaction_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$invoiceId, $gatewaySlug, $amount, $status, $gatewayTransactionId, (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Same insert as create(), but with an explicit created_at rather than
     * "now" — for importing historical transactions (R29) where the
     * original charge date matters and shouldn't be overwritten with the
     * import run's own timestamp.
     */
    public function createHistorical(int $invoiceId, string $gatewaySlug, float $amount, string $status, ?string $gatewayTransactionId, string $createdAt): int
    {
        return (int) $this->db->insert(
            'INSERT INTO transactions (invoice_id, gateway_slug, amount, status, gateway_transaction_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$invoiceId, $gatewaySlug, $amount, $status, $gatewayTransactionId, $createdAt]
        );
    }

    /**
     * Idempotency check for gateway callbacks/webhooks — both the redirect
     * return and the async webhook can independently confirm the same
     * charge, and must not double-credit the invoice.
     *
     * @return array<string, mixed>|null
     */
    public function findByGatewayTransactionId(string $gatewaySlug, string $gatewayTransactionId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM transactions WHERE gateway_slug = ? AND gateway_transaction_id = ? LIMIT 1',
            [$gatewaySlug, $gatewayTransactionId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forInvoice(int $invoiceId): array
    {
        return $this->db->select('SELECT * FROM transactions WHERE invoice_id = ? ORDER BY id', [$invoiceId]);
    }

    public function totalCompletedForInvoice(int $invoiceId): float
    {
        $row = $this->db->selectOne(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE invoice_id = ? AND status = 'completed'",
            [$invoiceId]
        );

        return (float) ($row['total'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->select('SELECT * FROM transactions ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    }
}
