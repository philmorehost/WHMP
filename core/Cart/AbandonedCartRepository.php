<?php

declare(strict_types=1);

namespace CodeVault\Cart;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Persists a lightweight snapshot of a session's cart so a cron sweep can
 * find carts that have sat untouched and email a recovery reminder.
 *
 * Nothing here stores sensitive session data — just the product lines a
 * visitor added, the promo code applied, and the running total. The session
 * itself remains the source of truth while the visitor is actively shopping;
 * this table is only what the AbandonedCartJob reads hours later.
 */
final class AbandonedCartRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Insert or refresh the snapshot for a session. Called on every cart
     * mutation (add/remove/promo) so `updated_at` reflects real activity and
     * the sweep's staleness check is meaningful.
     *
     * @param array<int, mixed> $items raw cart items (Cart::items() shape)
     * @param array<int, mixed> $pricedLines priced display lines (CartService::priceItems() lines)
     */
    public function upsertBySession(
        string $sessionId,
        array $items,
        array $pricedLines,
        ?string $promoCode,
        float $total,
        ?int $clientId,
        ?string $email,
        ?int $currencyId
    ): void {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->statement(
            <<<'SQL'
            INSERT INTO abandoned_carts
                (session_id, client_id, email, items, promo_code, total, currency_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                client_id = COALESCE(VALUES(client_id), client_id),
                email = COALESCE(VALUES(email), email),
                items = VALUES(items),
                promo_code = VALUES(promo_code),
                total = VALUES(total),
                currency_id = VALUES(currency_id),
                updated_at = VALUES(updated_at)
            SQL,
            [
                $sessionId,
                $clientId,
                $email,
                json_encode(['items' => $items, 'priced' => $pricedLines], JSON_UNESCAPED_UNICODE),
                $promoCode,
                $total,
                $currencyId,
                $now,
                $now,
            ]
        );
    }

    /**
     * Carts that have been idle for at least `$idleMinutes` and haven't
     * converted (no recovered_at) — and, unless `$allowRepeat`, that we
     * haven't already reminded this cooldown period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stale(int $idleMinutes, bool $allowRepeat = false, int $repeatEveryMinutes = 0): array
    {
        $cutoff = (new DateTimeImmutable("-{$idleMinutes} minutes"))->format('Y-m-d H:i:s');

        $sql = <<<'SQL'
            SELECT *
            FROM abandoned_carts
            WHERE recovered_at IS NULL
              AND updated_at < ?
        SQL;

        $bindings = [$cutoff];

        if (!$allowRepeat) {
            $sql .= ' AND reminder_sent_at IS NULL';
        } elseif ($repeatEveryMinutes > 0) {
            $reminderCutoff = (new DateTimeImmutable("-{$repeatEveryMinutes} minutes"))->format('Y-m-d H:i:s');
            $sql .= ' AND (reminder_sent_at IS NULL OR reminder_sent_at < ?)';
            $bindings[] = $reminderCutoff;
        }

        $sql .= ' ORDER BY updated_at ASC';

        return $this->db->select($sql, $bindings);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM abandoned_carts WHERE id = ?', [$id]);
    }

    public function markReminderSent(int $id): void
    {
        $this->db->update(
            'UPDATE abandoned_carts SET reminder_sent_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markRecoveredBySession(string $sessionId): void
    {
        $this->db->update(
            'UPDATE abandoned_carts SET recovered_at = ? WHERE session_id = ? AND recovered_at IS NULL',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $sessionId]
        );
    }

    public function deleteBySession(string $sessionId): void
    {
        $this->db->delete('DELETE FROM abandoned_carts WHERE session_id = ?', [$sessionId]);
    }
}
