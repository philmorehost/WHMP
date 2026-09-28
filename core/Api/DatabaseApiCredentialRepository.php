<?php

declare(strict_types=1);

namespace CodeVault\Api;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * DB-backed implementation of ApiCredentialRepository (blueprint §3) —
 * reads api_credentials rows and turns them into ApiCredential value
 * objects. This is the missing half of the external REST API: the
 * interface + authenticator existed since R0 but nothing implemented the
 * interface, so /api/* could never authenticate.
 */
final class DatabaseApiCredentialRepository implements ApiCredentialRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function findByKey(string $key): ?ApiCredential
    {
        $row = $this->db->selectOne('SELECT * FROM api_credentials WHERE api_key = ?', [$key]);

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function find(int $id): ?ApiCredential
    {
        $row = $this->db->selectOne('SELECT * FROM api_credentials WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<int, array<string, mixed>> raw rows, newest first — for the admin management screen */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM api_credentials ORDER BY id DESC');
    }

    /**
     * Creates a credential. Returns the plaintext secret ONLY here — it is
     * shown once to the admin and never stored (only its Argon2id hash is).
     *
     * @param array<int, string> $scopes
     * @return array{id: int, key: string, secret: string}
     */
    public function create(string $label, array $scopes, ?int $createdBy = null): array
    {
        $key = bin2hex(random_bytes(24));
        $secret = bin2hex(random_bytes(32));
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $id = (int) $this->db->insert(
            'INSERT INTO api_credentials (label, api_key, secret_hash, scopes, active, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)',
            [$label, $key, ApiCredential::hashSecret($secret), json_encode($scopes), $createdBy, $now, $now]
        );

        return [
            'id' => $id,
            'key' => $key,
            'secret' => $secret,
        ];
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->update(
            'UPDATE api_credentials SET active = ?, updated_at = ? WHERE id = ?',
            [$active ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function touchLastUsed(int $id): void
    {
        $this->db->update(
            'UPDATE api_credentials SET last_used_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM api_credentials WHERE id = ?', [$id]);
    }

    /** @return array<int, string> the canonical scope catalog offered in the admin UI */
    public static function scopeCatalog(): array
    {
        return [
            'clients.read',
            'clients.write',
            'invoices.read',
            'invoices.write',
            'services.read',
            'services.write',
            'domains.read',
            'domains.write',
            'tickets.read',
            'tickets.write',
            // Reseller keys get this and nothing else: the endpoints above read
            // the whole install, so a client-owned key must not hold them.
            'reseller.read',
        ];
    }

    // --- client-owned (reseller) credentials --------------------------------
    //
    // Unlike create()/setActive()/delete() above, which are the staff screen's,
    // every method here takes the client it expects the row to belong to and
    // puts that id in the WHERE clause. A wrong id therefore matches nothing
    // rather than touching someone else's key — the same guard the controllers
    // apply, enforced again at the point of the write.

    /** The client's reseller credential (newest first), or null if they have none. */
    /** @return array<string, mixed>|null */
    public function forClient(int $clientId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM api_credentials WHERE client_id = ? ORDER BY id DESC LIMIT 1',
            [$clientId]
        );
    }

    /**
     * Mints a CLIENT-owned credential. It is created INACTIVE and with no
     * storefront domain: activation is a separate, deliberate step
     * (activateForClient) that requires one.
     *
     * The plaintext secret is returned only here; the row keeps its Argon2id hash.
     *
     * @param array<int, string> $scopes
     * @return array{id: int, key: string, secret: string}
     */
    public function createForClient(int $clientId, string $label, array $scopes): array
    {
        $key = bin2hex(random_bytes(24));
        $secret = bin2hex(random_bytes(32));
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $id = (int) $this->db->insert(
            'INSERT INTO api_credentials (client_id, label, api_key, secret_hash, scopes, active, reseller_domain, activated_at, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, NULL, NULL, NULL, ?, ?)',
            [$clientId, $label, $key, ApiCredential::hashSecret($secret), json_encode($scopes), $now, $now]
        );

        return ['id' => $id, 'key' => $key, 'secret' => $secret];
    }

    /**
     * Switches a client's key on and records the domain it sells from.
     *
     * Ownership is checked with a SELECT first rather than inferred from the
     * UPDATE's affected-row count: MySQL reports 0 rows changed when the values
     * are already what was asked for, so "already activated with this domain"
     * would be indistinguishable from "not this client's key".
     */
    public function activateForClient(int $id, int $clientId, string $domain): bool
    {
        if (!$this->belongsToClient($id, $clientId)) {
            return false;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE api_credentials SET reseller_domain = ?, active = 1, activated_at = COALESCE(activated_at, ?), updated_at = ? WHERE id = ? AND client_id = ?',
            [$domain, $now, $now, $id, $clientId]
        );

        return true;
    }

    /** Enables or disables a client's key. False when the key is not theirs. */
    public function setActiveForClient(int $id, int $clientId, bool $active): bool
    {
        if (!$this->belongsToClient($id, $clientId)) {
            return false;
        }

        $this->db->update(
            'UPDATE api_credentials SET active = ?, updated_at = ? WHERE id = ? AND client_id = ?',
            [$active ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id, $clientId]
        );

        return true;
    }

    /**
     * Every client-owned credential, with the owning client attached — the
     * admin reseller screen's list. Staff-owned rows (client_id NULL) are
     * excluded: they belong to the API credentials screen, not this one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allResellers(): array
    {
        return $this->db->select(
            "SELECT ac.*, c.first_name, c.last_name, c.email
             FROM api_credentials ac
             JOIN clients c ON c.id = ac.client_id
             WHERE ac.client_id IS NOT NULL
             ORDER BY ac.id DESC"
        );
    }

    private function belongsToClient(int $id, int $clientId): bool
    {
        return $this->db->selectOne(
            'SELECT 1 FROM api_credentials WHERE id = ? AND client_id = ?',
            [$id, $clientId]
        ) !== null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ApiCredential
    {
        return new ApiCredential(
            (int) $row['id'],
            (string) $row['api_key'],
            (string) $row['secret_hash'],
            $this->decodeScopes((string) $row['scopes']),
            (int) $row['active'] === 1
        );
    }

    /** @return array<int, string> */
    private function decodeScopes(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }
}
