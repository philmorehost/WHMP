<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Sub-accounts (blueprint §4.1 "Contacts/Sub-accounts"). `permissions` is
 * stored as a JSON array of permission-scope strings the sub-account is
 * granted on the parent client's account.
 */
final class ClientContactRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->db->select('SELECT * FROM client_contacts WHERE client_id = ? ORDER BY name', [$clientId]);
    }

    /** @param array<int, string> $permissions */
    public function create(int $clientId, string $name, string $email, array $permissions = []): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO client_contacts (client_id, name, email, permissions, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$clientId, $name, $email, json_encode($permissions), $now, $now]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM client_contacts WHERE id = ?', [$id]);
    }

    /** Sub-accounts are pure PII with no independent financial-retention need, so GDPR erasure removes them outright rather than anonymizing. */
    public function deleteForClient(int $clientId): void
    {
        $this->db->delete('DELETE FROM client_contacts WHERE client_id = ?', [$clientId]);
    }
}
