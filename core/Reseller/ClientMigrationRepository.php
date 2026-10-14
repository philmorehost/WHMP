<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Plain SQL for client moves (migration 0208). The rules — who may ask, what a move
 * changes, what it must never touch — live in ClientMigrationService.
 */
final class ClientMigrationRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $fields */
    public function create(array $fields): int
    {
        $now = $this->now();

        return (int) $this->db->insert(
            'INSERT INTO client_migrations
                (client_id, client_email, from_reseller_id, from_label, target, to_reseller_id, target_label, target_input,
                 requested_by, requester_client_id, ticket_id, reason, status, admin_id, decision_note, summary, decided_at,
                 created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['client_id'] ?? null,
                strtolower(trim((string) ($fields['client_email'] ?? ''))),
                $fields['from_reseller_id'] ?? null,
                $fields['from_label'] ?? null,
                $fields['target'],
                $fields['to_reseller_id'] ?? null,
                $fields['target_label'] ?? null,
                $fields['target_input'] ?? null,
                $fields['requested_by'],
                $fields['requester_client_id'] ?? null,
                $fields['ticket_id'] ?? null,
                $fields['reason'] ?? null,
                $fields['status'] ?? 'pending',
                $fields['admin_id'] ?? null,
                $fields['decision_note'] ?? null,
                $fields['summary'] ?? null,
                $fields['decided_at'] ?? null,
                $now,
                $now,
            ]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM client_migrations WHERE id = ? LIMIT 1', [$id]);
    }

    public function attachTicket(int $id, int $ticketId): void
    {
        $this->db->update(
            'UPDATE client_migrations SET ticket_id = ?, updated_at = ? WHERE id = ?',
            [$ticketId, $this->now(), $id]
        );
    }

    /**
     * The open request for this client, if there is one — one at a time, so two tickets
     * asking for two different destinations cannot both be pending.
     *
     * @return array<string, mixed>|null
     */
    public function pendingForClient(int $clientId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM client_migrations WHERE client_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$clientId]
        );
    }

    /** @return array<string, mixed>|null */
    public function pendingForEmail(string $email): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM client_migrations WHERE client_email = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [strtolower(trim($email))]
        );
    }

    /**
     * The admin work list: oldest first, because the one waiting longest is the one
     * costing the most goodwill.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(int $limit = 200): array
    {
        return $this->db->select(
            "SELECT m.*, c.first_name, c.last_name, c.reseller_id AS current_reseller_id
             FROM client_migrations m
             LEFT JOIN clients c ON c.id = m.client_id
             WHERE m.status = 'pending'
             ORDER BY m.created_at ASC, m.id ASC
             LIMIT " . max(1, $limit)
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function decided(int $limit = 50): array
    {
        return $this->db->select(
            "SELECT m.*, c.first_name, c.last_name
             FROM client_migrations m
             LEFT JOIN clients c ON c.id = m.client_id
             WHERE m.status <> 'pending'
             ORDER BY COALESCE(m.decided_at, m.updated_at) DESC, m.id DESC
             LIMIT " . max(1, $limit)
        );
    }

    /**
     * What one client (or reseller owner) has asked for — their own requests only.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forRequester(int $clientId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM client_migrations WHERE requester_client_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$clientId]
        );
    }

    /**
     * Close a pending request. FALSE when it was not pending any more — two admins
     * pressing the button at once must not both act.
     *
     * @param array<string, mixed> $fields
     */
    public function decide(int $id, string $status, ?int $adminId, ?string $note, array $fields = []): bool
    {
        $now = $this->now();

        $sets = ['status = ?', 'admin_id = ?', 'decision_note = ?', 'decided_at = ?', 'updated_at = ?'];
        $values = [$status, $adminId, $note, $now, $now];

        foreach (['summary', 'from_reseller_id', 'from_label', 'to_reseller_id', 'target', 'target_label', 'client_id'] as $column) {
            if (array_key_exists($column, $fields)) {
                $sets[] = $column . ' = ?';
                $values[] = $fields[$column];
            }
        }

        $values[] = $id;

        return $this->db->update(
            'UPDATE client_migrations SET ' . implode(', ', $sets) . " WHERE id = ? AND status = 'pending'",
            $values
        ) > 0;
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
