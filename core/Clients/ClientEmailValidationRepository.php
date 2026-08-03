<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Database;
use DateTimeImmutable;

final class ClientEmailValidationRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function upsert(int $clientId, string $email, bool $isValid, ?string $reason, int $recentFailures): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->statement(
            <<<'SQL'
            INSERT INTO client_email_validations (client_id, email, is_valid, reason, recent_failures, checked_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE email = VALUES(email), is_valid = VALUES(is_valid), reason = VALUES(reason),
                recent_failures = VALUES(recent_failures), checked_at = VALUES(checked_at)
            SQL,
            [$clientId, $email, $isValid ? 1 : 0, $reason, $recentFailures, $now]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT v.*, c.first_name, c.last_name, c.status AS client_status
            FROM client_email_validations v
            JOIN clients c ON c.id = v.client_id
            ORDER BY v.is_valid ASC, v.recent_failures DESC, v.checked_at DESC
            SQL
        );
    }

    /** @return array{total: int, invalid: int, lastScanAt: ?string} */
    public function summary(): array
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS total, SUM(is_valid = 0) AS invalid, MAX(checked_at) AS last_scan_at FROM client_email_validations'
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'invalid' => (int) ($row['invalid'] ?? 0),
            'lastScanAt' => $row['last_scan_at'] ?? null,
        ];
    }
}
