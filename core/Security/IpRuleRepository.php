<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * One row per IP BruteGuard has ever acted on: a tiered blacklist block
 * (day/week/month/year, escalating with repeat offenses) or a whitelist
 * entry (the "King" badge) — plus a running clean-session count for IPs
 * that haven't earned either yet (blueprint §5).
 */
final class IpRuleRepository
{
    private const TIERS = ['day', 'week', 'month', 'year'];

    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(string $ip): ?array
    {
        return $this->db->selectOne('SELECT * FROM security_ip_rules WHERE ip_address = ?', [$ip]);
    }

    /** @return array<int, array<string, mixed>> every IP with a rule (blocked, whitelisted, or mid-tracking) */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM security_ip_rules ORDER BY updated_at DESC');
    }

    public function isBlocked(string $ip): bool
    {
        $row = $this->find($ip);

        if ($row === null || $row['policy'] !== 'blacklisted') {
            return false;
        }

        return $row['expires_at'] === null || $row['expires_at'] > $this->now();
    }

    public function isWhitelisted(string $ip): bool
    {
        $row = $this->find($ip);

        return $row !== null && $row['policy'] === 'whitelisted';
    }

    /**
     * Blacklists an IP, escalating the tier based on how many times it's
     * been blocked before (day -> week -> month -> year, then stays year).
     */
    public function blacklist(string $ip, string $reason, string $source = 'auto', ?int $adminId = null): string
    {
        $row = $this->find($ip);
        $blockCount = (int) ($row['block_count'] ?? 0) + 1;
        $tierIndex = min($blockCount - 1, count(self::TIERS) - 1);
        $tier = self::TIERS[$tierIndex];
        $now = $this->now();

        $this->upsert($ip, [
            'policy' => 'blacklisted',
            'tier' => $tier,
            'source' => $source,
            'reason' => $reason,
            'admin_id' => $adminId,
            'block_count' => $blockCount,
            'clean_session_count' => 0,
            'updated_at' => $now,
            'expires_at' => $this->expiryForTier($tier),
        ], $row);

        return $tier;
    }

    public function whitelist(string $ip, string $reason, string $source = 'auto', ?int $adminId = null): void
    {
        $row = $this->find($ip);

        $this->upsert($ip, [
            'policy' => 'whitelisted',
            'tier' => null,
            'source' => $source,
            'reason' => $reason,
            'admin_id' => $adminId,
            'updated_at' => $this->now(),
            'expires_at' => null,
        ], $row);
    }

    public function clear(string $ip): void
    {
        $this->db->delete('DELETE FROM security_ip_rules WHERE ip_address = ?', [$ip]);
    }

    /**
     * Increments the clean-session streak for an IP; returns the new count.
     * Does nothing to an already-whitelisted IP (it keeps its status).
     */
    public function recordCleanSession(string $ip): int
    {
        $row = $this->find($ip);

        if ($row !== null && $row['policy'] === 'whitelisted') {
            return (int) $row['clean_session_count'];
        }

        $count = (int) ($row['clean_session_count'] ?? 0) + 1;

        $this->upsert($ip, [
            'policy' => $row['policy'] ?? null,
            'tier' => $row['tier'] ?? null,
            'source' => $row['source'] ?? 'auto',
            'reason' => $row['reason'] ?? null,
            'admin_id' => $row['admin_id'] ?? null,
            'clean_session_count' => $count,
            'updated_at' => $this->now(),
            'expires_at' => $row['expires_at'] ?? null,
        ], $row);

        return $count;
    }

    public function resetCleanSessions(string $ip): void
    {
        $row = $this->find($ip);

        if ($row === null || $row['policy'] === 'whitelisted') {
            return;
        }

        $this->db->update('UPDATE security_ip_rules SET clean_session_count = 0, updated_at = ? WHERE ip_address = ?', [$this->now(), $ip]);
    }

    /** @param array<string, mixed> $fields */
    private function upsert(string $ip, array $fields, ?array $existingRow): void
    {
        if ($existingRow === null) {
            $this->db->insert(
                'INSERT INTO security_ip_rules (ip_address, policy, tier, source, reason, admin_id, clean_session_count, block_count, created_at, updated_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $ip,
                    $fields['policy'] ?? null,
                    $fields['tier'] ?? null,
                    $fields['source'] ?? 'auto',
                    $fields['reason'] ?? null,
                    $fields['admin_id'] ?? null,
                    $fields['clean_session_count'] ?? 0,
                    $fields['block_count'] ?? 0,
                    $this->now(),
                    $this->now(),
                    $fields['expires_at'] ?? null,
                ]
            );

            return;
        }

        $this->db->update(
            'UPDATE security_ip_rules SET policy = ?, tier = ?, source = ?, reason = ?, admin_id = ?, clean_session_count = ?, block_count = ?, updated_at = ?, expires_at = ? WHERE ip_address = ?',
            [
                $fields['policy'] ?? $existingRow['policy'],
                $fields['tier'] ?? $existingRow['tier'],
                $fields['source'] ?? $existingRow['source'],
                $fields['reason'] ?? $existingRow['reason'],
                $fields['admin_id'] ?? $existingRow['admin_id'],
                $fields['clean_session_count'] ?? $existingRow['clean_session_count'],
                $fields['block_count'] ?? $existingRow['block_count'],
                $fields['updated_at'] ?? $this->now(),
                array_key_exists('expires_at', $fields) ? $fields['expires_at'] : $existingRow['expires_at'],
                $ip,
            ]
        );
    }

    private function expiryForTier(string $tier): string
    {
        $interval = match ($tier) {
            'day' => '+1 day',
            'week' => '+1 week',
            'month' => '+1 month',
            'year' => '+1 year',
            default => '+1 day',
        };

        return (new DateTimeImmutable($interval))->format('Y-m-d H:i:s');
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
