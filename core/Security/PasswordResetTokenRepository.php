<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Database;
use DateTimeImmutable;

final class PasswordResetTokenRepository
{
    public const TTL_MINUTES = 60;

    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Deletes any prior tokens for this account before issuing a new one —
     * only the most recently requested reset link is ever valid, so
     * requesting a fresh link silently invalidates an older, possibly
     * already-forwarded/leaked one.
     */
    public function issue(string $accountType, int $accountId, string $tokenHash): void
    {
        $this->deleteForAccount($accountType, $accountId);

        $now = new DateTimeImmutable();

        $this->db->insert(
            'INSERT INTO password_reset_tokens (account_type, account_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                $accountType,
                $accountId,
                $tokenHash,
                $now->modify('+' . self::TTL_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ]
        );
    }

    /** @return array<string, mixed>|null the token row, only if it exists, matches the account type, and hasn't expired */
    public function findValid(string $accountType, string $tokenHash): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM password_reset_tokens WHERE account_type = ? AND token_hash = ? AND expires_at > ?',
            [$accountType, $tokenHash, (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    public function consume(int $id): void
    {
        $this->db->delete('DELETE FROM password_reset_tokens WHERE id = ?', [$id]);
    }

    public function deleteForAccount(string $accountType, int $accountId): void
    {
        $this->db->delete('DELETE FROM password_reset_tokens WHERE account_type = ? AND account_id = ?', [$accountType, $accountId]);
    }

    /**
     * True when a reset link was issued to this account within the last
     * $withinSeconds. The forgot-password handlers are public (no login) and
     * send one real email per call, so without this cooldown a scripted
     * caller could email-bomb any known client/admin address with branded
     * reset mail through the app's own transport.
     */
    public function recentlyIssued(string $accountType, int $accountId, int $withinSeconds = 60): bool
    {
        $row = $this->db->selectOne(
            'SELECT created_at FROM password_reset_tokens WHERE account_type = ? AND account_id = ? ORDER BY created_at DESC LIMIT 1',
            [$accountType, $accountId]
        );

        return $row !== null && $row['created_at'] !== null
            && (time() - (int) strtotime((string) $row['created_at'])) < $withinSeconds;
    }

    /**
     * Expired-but-never-consumed tokens (the link was never clicked)
     * otherwise sit in the table forever — findValid() filters them out at
     * read time, but nothing ever deletes them. Used by DataPruningJob.
     */
    public function deleteExpired(): int
    {
        return $this->db->delete('DELETE FROM password_reset_tokens WHERE expires_at < ?', [(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }
}
