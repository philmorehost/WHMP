<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * The raw event log BruteGuard reads sliding-window failure counts from,
 * and the source for the admin live log panel (blueprint §5).
 */
final class LoginAttemptRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function record(?string $username, string $ip, ?string $countryCode, bool $successful, ?string $userAgent = null): void
    {
        $this->db->insert(
            'INSERT INTO security_login_attempts (username, ip_address, country_code, successful, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$username, $ip, $countryCode, $successful ? 1 : 0, $userAgent, (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    public function countFailuresByIp(string $ip, int $minutes): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM security_login_attempts WHERE ip_address = ? AND successful = 0 AND created_at >= ?',
            [$ip, $this->since($minutes)]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countFailuresByUsername(string $username, int $minutes): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM security_login_attempts WHERE username = ? AND successful = 0 AND created_at >= ?',
            [$username, $this->since($minutes)]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array<int, int> $windowsMinutes
     * @return array<int, int> window (minutes) => failure count, for the live log panel
     */
    public function failureSummaryForIp(string $ip, array $windowsMinutes = [5, 10, 15, 60]): array
    {
        $summary = [];

        foreach ($windowsMinutes as $minutes) {
            $summary[$minutes] = $this->countFailuresByIp($ip, $minutes);
        }

        return $summary;
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM security_login_attempts ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    /** Used by DataPruningJob (blueprint §4.4 "pruning automation") — deletes rows older than the given timestamp, returns how many were removed. */
    public function deleteOlderThan(string $beforeDateTime): int
    {
        return $this->db->delete('DELETE FROM security_login_attempts WHERE created_at < ?', [$beforeDateTime]);
    }

    private function since(int $minutes): string
    {
        return (new DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s');
    }
}
