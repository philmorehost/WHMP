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
        ];
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
