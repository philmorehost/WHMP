<?php

declare(strict_types=1);

namespace CodeVault\Ai;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Append-only log of AI provider calls, powering the admin AI-management
 * dashboard's usage view. Kept deliberately small — a row per call with
 * token counts and success — so it's cheap to write on every completion.
 */
final class AiUsageRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function record(string $feature, string $provider, int $promptTokens, int $completionTokens, bool $success): void
    {
        $this->db->insert(
            'INSERT INTO ai_usage_log (feature, provider, prompt_tokens, completion_tokens, total_tokens, success, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$feature, $provider, $promptTokens, $completionTokens, $promptTokens + $completionTokens, $success ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    /** @return array{calls: int, tokens: int, failures: int, calls_30d: int, tokens_30d: int} */
    public function totals(): array
    {
        $all = $this->db->selectOne('SELECT COUNT(*) AS calls, COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END),0) AS failures FROM ai_usage_log');
        $cutoff = (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
        $recent = $this->db->selectOne('SELECT COUNT(*) AS calls, COALESCE(SUM(total_tokens),0) AS tokens FROM ai_usage_log WHERE created_at >= ?', [$cutoff]);

        return [
            'calls' => (int) ($all['calls'] ?? 0),
            'tokens' => (int) ($all['tokens'] ?? 0),
            'failures' => (int) ($all['failures'] ?? 0),
            'calls_30d' => (int) ($recent['calls'] ?? 0),
            'tokens_30d' => (int) ($recent['tokens'] ?? 0),
        ];
    }

    /** @return array<int, array<string, mixed>> usage totals grouped by feature */
    public function byFeature(): array
    {
        return $this->db->select('SELECT feature, COUNT(*) AS calls, COALESCE(SUM(total_tokens),0) AS tokens FROM ai_usage_log GROUP BY feature ORDER BY calls DESC');
    }

    /** @return array<int, array<string, mixed>> the most recent calls */
    public function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return $this->db->select("SELECT * FROM ai_usage_log ORDER BY id DESC LIMIT {$limit}");
    }
}
