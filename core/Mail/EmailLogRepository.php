<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Every outbound email lands a row here regardless of outcome — the source
 * for "My Emails" (client area, future) and the admin Email Log.
 */
final class EmailLogRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function create(string $toEmail, string $subject, ?string $templateKey, ?int $clientId): int
    {
        return (int) $this->db->insert(
            'INSERT INTO email_log (to_email, subject, template_key, client_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$toEmail, $subject, $templateKey, $clientId, 'queued', (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    public function markSent(int $id): void
    {
        $this->db->update(
            'UPDATE email_log SET status = ?, sent_at = ? WHERE id = ?',
            ['sent', (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markFailed(int $id, string $error): void
    {
        $this->db->update('UPDATE email_log SET status = ?, error = ? WHERE id = ?', ['failed', $error, $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->select('SELECT * FROM email_log ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM email_log WHERE client_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            [$clientId]
        );
    }

    /** Used by DataPruningJob (blueprint §4.4 "pruning automation") — deletes rows older than the given timestamp, returns how many were removed. */
    public function deleteOlderThan(string $beforeDateTime): int
    {
        return $this->db->delete('DELETE FROM email_log WHERE created_at < ?', [$beforeDateTime]);
    }
}
