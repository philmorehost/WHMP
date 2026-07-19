<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Valid-user max-fails -> account lock (blueprint §5) — independent of any
 * IP-level action, a locked account stays locked regardless of which IP
 * tries it next.
 */
final class AccountLockRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function isLocked(int $adminId): bool
    {
        return $this->activeLock($adminId) !== null;
    }

    /** @return array<string, mixed>|null */
    public function activeLock(int $adminId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM security_account_locks WHERE admin_id = ? AND (expires_at IS NULL OR expires_at > ?) ORDER BY id DESC LIMIT 1',
            [$adminId, (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );

        return $row;
    }

    public function lock(int $adminId, ?int $minutesUntilExpiry, string $reason): void
    {
        $expiresAt = $minutesUntilExpiry === null
            ? null
            : (new DateTimeImmutable("+{$minutesUntilExpiry} minutes"))->format('Y-m-d H:i:s');

        $this->db->insert(
            'INSERT INTO security_account_locks (admin_id, locked_at, expires_at, reason) VALUES (?, ?, ?, ?)',
            [$adminId, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $expiresAt, $reason]
        );
    }

    public function unlock(int $adminId): void
    {
        $this->db->delete('DELETE FROM security_account_locks WHERE admin_id = ?', [$adminId]);
    }

    /** @return array<int, array<string, mixed>> currently-locked accounts, joined with admin username */
    public function activeLocks(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT l.*, a.username, a.display_name
            FROM security_account_locks l
            INNER JOIN admins a ON a.id = l.admin_id
            WHERE l.expires_at IS NULL OR l.expires_at > ?
            ORDER BY l.locked_at DESC
            SQL,
            [(new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }
}
