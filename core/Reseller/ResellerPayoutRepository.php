<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;

/**
 * Payout requests: storage only, no money rules.
 *
 * The rules — how much may be requested, what a rejection does to the account,
 * which transitions are legal — live in ResellerPayoutService. This class only
 * reads and writes rows, so the places where money can change hands are all in
 * one file a reviewer can read end to end.
 *
 * `open_flag` is deliberately never selected. It exists so the storage engine can
 * enforce "at most one pending request per reseller" (see migration 0191); the
 * values that matter to every reader are `status` and the two amount columns, and
 * a generated helper column leaking into a view or an API response would invite
 * someone to make a decision from it.
 */
final class ResellerPayoutRepository
{
    /** The columns every reader gets, so no query accidentally exposes open_flag. */
    private const COLUMNS = 'id, reseller_id, client_id, amount, currency_id, currency_rate, '
        . 'amount_base, status, method, reference, note, destination_snapshot, requested_at, decided_at, decided_by';

    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM reseller_payouts WHERE id = ?',
            [$id]
        );
    }

    /**
     * The reseller's open request, if there is one.
     *
     * At most one can exist — the unique key on (reseller_id, open_flag) makes a
     * second impossible — so this needs no ORDER BY to be unambiguous.
     *
     * @return array<string, mixed>|null
     */
    public function openForReseller(int $resellerId): ?array
    {
        return $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM reseller_payouts
             WHERE reseller_id = ? AND status = ? LIMIT 1',
            [$resellerId, 'pending']
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forReseller(int $resellerId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM reseller_payouts
             WHERE reseller_id = ? ORDER BY requested_at DESC, id DESC LIMIT ' . max(1, $limit),
            [$resellerId]
        );
    }

    /**
     * The admin queue: pending requests oldest first.
     *
     * Oldest first because the queue is a work list, and the request that has been
     * waiting longest is the one that has been waiting longest.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(int $limit = 200): array
    {
        return $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM reseller_payouts
             WHERE status = ? ORDER BY requested_at ASC, id ASC LIMIT ' . max(1, $limit),
            ['pending']
        );
    }

    /** Recently decided requests, for the admin's history. @return array<int, array<string, mixed>> */
    public function decided(int $limit = 100): array
    {
        return $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM reseller_payouts
             WHERE status <> ? ORDER BY decided_at DESC, id DESC LIMIT ' . max(1, $limit),
            ['pending']
        );
    }

    /** @param array<string, mixed> $row */
    public function create(array $row): int
    {
        return (int) $this->db->insert(
            'INSERT INTO reseller_payouts
                (reseller_id, client_id, amount, currency_id, currency_rate, amount_base,
                 status, method, reference, note, destination_snapshot, requested_at, decided_at, decided_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $row['reseller_id'],
                $row['client_id'] === null ? null : (int) $row['client_id'],
                (float) $row['amount'],
                $row['currency_id'] === null ? null : (int) $row['currency_id'],
                (float) $row['currency_rate'],
                (float) $row['amount_base'],
                (string) $row['status'],
                (string) $row['method'],
                $row['reference'] ?? null,
                $row['note'] ?? null,
                // Frozen at request time. A later change to the stored
                // destination must not restate where this money was sent.
                $row['destination_snapshot'] ?? null,
                (string) $row['requested_at'],
                $row['decided_at'] ?? null,
                $row['decided_by'] === null ? null : (int) $row['decided_by'],
            ]
        );
    }

    /**
     * Move a request to a decided status.
     *
     * The `status = 'pending'` guard is in the WHERE clause rather than checked
     * first, so two admins deciding the same request at the same moment cannot
     * both succeed — the second update matches no row. Returns whether THIS call
     * was the one that decided it, which is what makes "already decided" a
     * detectable outcome instead of a silent overwrite of someone else's decision.
     */
    public function decide(int $id, string $status, ?string $reference, ?string $note, ?int $adminId, string $at): bool
    {
        return $this->db->update(
            'UPDATE reseller_payouts
                SET status = ?, reference = ?, note = ?, decided_at = ?, decided_by = ?
              WHERE id = ? AND status = ?',
            [$status, $reference, $note, $at, $adminId, $id, 'pending']
        ) > 0;
    }

    /**
     * How many requests are in each state, and their base-currency totals.
     *
     * @return array<string, array<string, mixed>>
     */
    public function totalsByStatus(): array
    {
        $rows = $this->db->select(
            'SELECT status, COUNT(*) AS payout_count, COALESCE(SUM(amount_base), 0) AS total_base
             FROM reseller_payouts GROUP BY status'
        );

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row['status']] = [
                'count' => (int) $row['payout_count'],
                'total_base' => round((float) $row['total_base'], 2),
            ];
        }

        return $totals;
    }
}
