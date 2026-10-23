<?php

declare(strict_types=1);

namespace CodeVault\Api;

use CodeVault\Database;
use Throwable;

/**
 * Fixed-window rate limiting for the external REST API.
 *
 * The public API documentation has always advertised `429 Too many requests`
 * — nothing enforced it. This makes the promise real: every /api request is
 * counted against its source (the caller's IP, pre-authentication, so even
 * credential-guessing attempts are bounded) and when the allowance is
 * exhausted the Kernel answers 429 with a Retry-After header before any
 * route runs.
 *
 * Storage is a single MariaDB table (api_rate_limits, migration 0223) with a
 * per-(identifier, window) row upserted atomically — no Redis requirement,
 * no in-memory state to lose between FPM workers. Windows are aligned to
 * wall-clock boundaries so the retry-after an attacker can compute from the
 * response always matches the window the server is actually counting.
 */
final class ApiRateLimiter
{
    /** Requests allowed per identifier per window (the documented default). */
    public const MAX_REQUESTS = 300;

    /** Window length in seconds. */
    public const WINDOW_SECONDS = 60;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Record one hit for $identifier. Returns true when the caller has
     * EXCEEDED the allowance and the request should be rejected with 429;
     * the Nth allowed request returns false — rejection starts only once
     * the limit is actually passed, matching what the docs promise.
     */
    public function hit(string $identifier, int $maxRequests = self::MAX_REQUESTS, int $windowSeconds = self::WINDOW_SECONDS): bool
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);

        try {
            $this->db->statement(
                'INSERT INTO api_rate_limits (identifier, window_start, hits) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1',
                [$identifier, $windowStart]
            );

            $row = $this->db->selectOne(
                'SELECT hits FROM api_rate_limits WHERE identifier = ? AND window_start = ?',
                [$identifier, $windowStart]
            );

            // Opportunistic cleanup of long-dead windows — cheap, indexed, and
            // bounded by the table's own key so it never grows unbounded.
            if (random_int(1, 100) === 1) {
                $this->db->statement(
                    'DELETE FROM api_rate_limits WHERE window_start < ?',
                    [$now - (3 * $windowSeconds)]
                );
            }
        } catch (Throwable) {
            // A limiter that cannot reach its table must never take the API
            // down with it: authentication still gates every endpoint, so the
            // safe degradation is to allow the request uncounted.
            return false;
        }

        return (int) ($row['hits'] ?? 0) > $maxRequests;
    }

    /** Seconds until the current window rolls over (for the Retry-After header). */
    public static function retryAfter(int $windowSeconds = self::WINDOW_SECONDS): int
    {
        return $windowSeconds - (time() % $windowSeconds);
    }
}
