<?php

declare(strict_types=1);

namespace CodeVault\Auth;

use CodeVault\Database;
use DateTimeImmutable;

final class AdminRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->db->selectOne('SELECT * FROM admins WHERE username = ?', [$username]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne('SELECT * FROM admins WHERE email = ?', [$email]);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM admins WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> admins joined with their role name */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT a.*, r.name AS role_name
            FROM admins a
            LEFT JOIN roles r ON r.id = a.role_id
            ORDER BY a.display_name
            SQL
        );
    }

    public function create(string $username, string $email, string $plainPassword, string $displayName, ?int $roleId): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$username, $email, password_hash($plainPassword, PASSWORD_ARGON2ID), $displayName, $roleId, $now, $now]
        );
    }

    public function updateProfile(int $id, string $email, string $displayName, ?int $roleId): void
    {
        $this->db->update(
            'UPDATE admins SET email = ?, display_name = ?, role_id = ?, updated_at = ? WHERE id = ?',
            [$email, $displayName, $roleId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updatePassword(int $id, string $plainPassword): void
    {
        $this->db->update(
            'UPDATE admins SET password_hash = ?, updated_at = ? WHERE id = ?',
            [password_hash($plainPassword, PASSWORD_ARGON2ID), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM admins WHERE id = ?', [$id]);
    }

    /**
     * Stores a freshly-generated secret + recovery codes but does NOT yet
     * flag 2FA enabled — that only happens once the admin proves they can
     * actually generate a valid code with it (confirmTwoFactor()),
     * matching every real-world 2FA enrollment flow.
     */
    public function pendingTwoFactorSecret(int $id, string $secret, string $hashedRecoveryCodes): void
    {
        $this->db->update(
            'UPDATE admins SET two_factor_secret = ?, two_factor_recovery_codes = ?, two_factor_enabled = 0, updated_at = ? WHERE id = ?',
            [$secret, $hashedRecoveryCodes, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function confirmTwoFactor(int $id): void
    {
        $this->db->update(
            'UPDATE admins SET two_factor_enabled = 1, updated_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function disableTwoFactor(int $id): void
    {
        $this->db->update(
            'UPDATE admins SET two_factor_secret = NULL, two_factor_enabled = 0, two_factor_recovery_codes = NULL, updated_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function updateRecoveryCodes(int $id, string $hashedRecoveryCodes): void
    {
        $this->db->update(
            'UPDATE admins SET two_factor_recovery_codes = ?, updated_at = ? WHERE id = ?',
            [$hashedRecoveryCodes, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }
}
