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

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM client_contacts WHERE id = ?', [$id]);
    }

    /**
     * @param array<int, string> $permissions
     * @param array<string, mixed> $details optional full WHOIS fields
     *        (company_name, address1, city, state, postcode, country, phone)
     */
    public function create(int $clientId, string $name, string $email, array $permissions = [], array $details = []): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $detailColumns = ['company_name', 'address1', 'city', 'state', 'postcode', 'country', 'phone'];
        $columns = ['client_id', 'name', 'email', 'permissions', 'created_at', 'updated_at'];
        $values = [$clientId, $name, $email, json_encode($permissions), $now, $now];

        foreach ($detailColumns as $col) {
            if (array_key_exists($col, $details)) {
                $columns[] = $col;
                $values[] = $details[$col] !== '' ? $details[$col] : null;
            }
        }

        $sql = 'INSERT INTO client_contacts (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';

        return (int) $this->db->insert($sql, $values);
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
